<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\AirGapViolationException;
use App\Services\Security\SentinelHttpClientGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Throwable;

class LocalAiGatewayService
{
    public function __construct(
        private readonly SentinelHttpClientGuard $guard,
    ) {}

    /**
     * Assert strict air-gap and local-only inference compliance before executing any AI workload.
     *
     * @throws AirGapViolationException
     */
    public function assertLocalCompliance(): void
    {
        $airGapped = config()->boolean('sentinel.air_gapped', true);
        $driver = config()->string('sentinel.ai.driver', 'local_hybrid');
        $cloudEnabled = config()->boolean('sentinel.ai.cloud_ai_enabled', false);
        $cloudEndpoint = trim(config()->string('sentinel.ai.cloud_endpoint', ''));

        if ($airGapped && ($cloudEnabled || $driver === 'cloud_openai' || $cloudEndpoint !== '')) {
            $parsedHost = parse_url($cloudEndpoint, PHP_URL_HOST);
            $host = is_string($parsedHost) && $parsedHost !== '' ? $parsedHost : $driver;
            $url = $cloudEndpoint !== '' ? $cloudEndpoint : "ai://{$driver}/cloud";

            throw new AirGapViolationException($host, $url);
        }
    }

    /**
     * Compute a normalized dense embedding vector locally via Ollama (bge-m3) or offline feature projection.
     *
     * @return array{vector: list<float>, model: string}
     */
    public function embed(string $text): array
    {
        $this->assertLocalCompliance();

        $driver = config()->string('sentinel.ai.driver', 'local_hybrid');
        $embedModel = config()->string('sentinel.ai.embed_model', 'bge-m3');

        if ($driver === 'ollama') {
            $ollamaUrl = rtrim(config()->string('sentinel.ai.ollama_url', 'http://ollama:11434'), '/');

            try {
                $response = Http::withRequestMiddleware(
                    fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
                )->timeout(10)->post("{$ollamaUrl}/api/embeddings", [
                    'model' => $embedModel,
                    'prompt' => $text,
                ]);

                if ($response->successful()) {
                    $raw = $response->json('embedding');
                    if (is_array($raw) && $raw !== []) {
                        /** @var list<float> $vec */
                        $vec = [];
                        foreach ($raw as $val) {
                            if (is_numeric($val)) {
                                $vec[] = (float) $val;
                            }
                        }
                        if ($vec !== []) {
                            return [
                                'vector' => $this->l2Normalize($vec),
                                'model' => $embedModel,
                            ];
                        }
                    }
                }
            } catch (AirGapViolationException $e) {
                throw $e;
            } catch (Throwable $e) {
                Log::info('Local Ollama embedding fallback triggered cleanly.', ['reason' => $e->getMessage()]);
            }
        }

        $dims = max(32, min(1024, config()->integer('sentinel.ai.embedding_dims', 128)));

        return [
            'vector' => $this->computeDeterministicEmbedding($text, $dims),
            'model' => 'sentinel-local-bge-proj',
        ];
    }

    /**
     * Execute local text generation via Ollama (qwen2.5) when running, or signal deterministic local synthesis.
     *
     * @return array{text: string|null, model: string}
     */
    public function generateText(string $systemPrompt, string $userPrompt): array
    {
        $this->assertLocalCompliance();

        $driver = config()->string('sentinel.ai.driver', 'local_hybrid');
        $chatModel = config()->string('sentinel.ai.chat_model', 'qwen2.5:7b-instruct');

        if ($driver === 'ollama') {
            $ollamaUrl = rtrim(config()->string('sentinel.ai.ollama_url', 'http://ollama:11434'), '/');

            try {
                $response = Http::withRequestMiddleware(
                    fn (RequestInterface $req): RequestInterface => $this->guard->assertOutboundAllowed($req)
                )->timeout(25)->post("{$ollamaUrl}/api/generate", [
                    'model' => $chatModel,
                    'system' => $systemPrompt,
                    'prompt' => $userPrompt,
                    'stream' => false,
                ]);

                if ($response->successful()) {
                    $text = $response->json('response');
                    if (is_string($text) && trim($text) !== '') {
                        return [
                            'text' => trim($text),
                            'model' => $chatModel,
                        ];
                    }
                }
            } catch (AirGapViolationException $e) {
                throw $e;
            } catch (Throwable $e) {
                Log::info('Local Ollama generation fallback triggered cleanly.', ['reason' => $e->getMessage()]);
            }
        }

        return [
            'text' => null,
            'model' => 'sentinel-local-extractive-v1',
        ];
    }

    /**
     * Compute cosine similarity between two equal-dimension float vectors.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $dot / (sqrt($normA) * sqrt($normB))));
    }

    /**
     * Deterministic signed feature-hashing vector projection preserving lexical & character n-gram similarity.
     *
     * @return list<float>
     */
    private function computeDeterministicEmbedding(string $text, int $dims): array
    {
        $vec = array_fill(0, $dims, 0.0);
        $clean = mb_strtolower(trim($text));
        if ($clean === '') {
            return $vec;
        }

        preg_match_all('/[a-z0-9]+/u', $clean, $matches);
        /** @var list<string> $tokens */
        $tokens = $matches[0];

        foreach ($tokens as $tok) {
            $h = abs(crc32('tok:'.$tok));
            $idx = $h % $dims;
            $sign = ((($h >> 1) & 1) === 1) ? 1.0 : -1.0;
            $vec[$idx] += $sign * (1.0 + (mb_strlen($tok) >= 5 ? 0.4 : 0.0));

            // Add character trigrams so partial/stem variations stay close in vector space
            $tLen = mb_strlen($tok);
            if ($tLen >= 3) {
                for ($i = 0; $i <= $tLen - 3; $i++) {
                    $tri = mb_substr($tok, $i, 3);
                    $th = abs(crc32('tri:'.$tri));
                    $tIdx = $th % $dims;
                    $tSign = ((($th >> 2) & 1) === 1) ? 1.0 : -1.0;
                    $vec[$tIdx] += $tSign * 0.35;
                }
            }
        }

        return $this->l2Normalize(array_values($vec));
    }

    /**
     * @param  list<float>  $vec
     * @return list<float>
     */
    private function l2Normalize(array $vec): array
    {
        $sqSum = 0.0;
        foreach ($vec as $v) {
            $sqSum += $v * $v;
        }

        if ($sqSum <= 0.0) {
            return $vec;
        }

        $inv = 1.0 / sqrt($sqSum);
        $out = [];
        foreach ($vec as $v) {
            $out[] = round($v * $inv, 6);
        }

        return $out;
    }
}
