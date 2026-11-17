<?php

declare(strict_types=1);

namespace App\Policies;

use App\Exceptions\DocumentAccessDeniedException;
use App\Models\Document;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Security\ClearancePolicyEngine;
use Illuminate\Auth\Access\Response;

class DocumentPolicy
{
    public function __construct(
        private readonly ClearancePolicyEngine $engine,
    ) {}

    public function view(User $user, Document $document): Response
    {
        return $this->evaluate($user, 'view', $document);
    }

    public function update(User $user, Document $document): Response
    {
        return $this->evaluate($user, 'update', $document);
    }

    public function delete(User $user, Document $document): Response
    {
        return $this->evaluate($user, 'delete', $document);
    }

    public function share(User $user, Document $document): Response
    {
        return $this->evaluate($user, 'share', $document);
    }

    public function print(User $user, Document $document): Response
    {
        return $this->evaluate($user, 'print', $document);
    }

    public function download(User $user, Document $document): Response
    {
        return $this->evaluate($user, 'download', $document);
    }

    public function sign(User $user, Document $document): Response
    {
        return $this->evaluate($user, 'sign', $document);
    }

    private function evaluate(User $user, string $action, Document $document): Response
    {
        $request = request();
        /** @var UserSession|null $session */
        $session = UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $request->session()->getId())
            ->whereNull('revoked_at')
            ->first();

        $decision = $this->engine->authorize($user, $action, $document, [
            'ip' => $request->ip(),
            'session' => $session,
        ]);

        if (! $decision->allowed) {
            if ($decision->hideExistence) {
                throw new DocumentAccessDeniedException('Document not found.');
            }

            return Response::deny($decision->reason);
        }

        return Response::allow();
    }
}
