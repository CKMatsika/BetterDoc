<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PrincipalType;
use App\Enums\PrintJobStatus;
use App\Enums\ScanSourceType;
use App\Models\Cabinet;
use App\Models\ClearanceLevel;
use App\Models\CostCentre;
use App\Models\Document;
use App\Models\DocumentAclGrant;
use App\Models\IdpExtraction;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\ScanBatch;
use App\Models\ScanSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PrintScan\PrintScanBridgeService;
use App\Services\Security\DbSecurityContext;
use Database\Seeders\AfzDefenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Smalot\PdfParser\Parser;

beforeEach(function (): void {
    $this->seed([AfzDefenceSeeder::class]);
});

it('blocks printing Secret documents on Internal-capped printers and enforces print permission and cost-centre quota', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    /** @var User $cpl */
    $cpl = User::query()->where('email', 'cpl.sibanda@afz.mil.zw')->firstOrFail();

    $dbContext = app(DbSecurityContext::class);

    $secretOps = $dbContext->runAs(
        $wgCdr,
        static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail()
    );
    $publicRoster = $dbContext->runAs(
        $wgCdr,
        static fn (): Document => Document::query()->where('doc_number', 'AFZ/SIG/2026/001')->firstOrFail()
    );

    [$lobbyPrinter, $opsPrinter, $costCentre] = $dbContext->runAs($wgCdr, static function () use ($tenant): array {
        return [
            Printer::query()->where('tenant_id', $tenant->id)->where('cups_queue_name', 'afz-lobby-l1')->firstOrFail(),
            Printer::query()->where('tenant_id', $tenant->id)->where('cups_queue_name', 'afz-ops-secret')->firstOrFail(),
            CostCentre::query()->where('tenant_id', $tenant->id)->where('code', 'CC-HQ-OPS')->firstOrFail(),
        ];
    });

    // 1. Secret doc (Rank 3) CANNOT print to Lobby printer (Max Rank 1)
    $this->signIn($wgCdr);
    $this->get('/print-scan')->assertOk();

    $this->post("/documents/{$secretOps->id}/print", [
        'printer_id' => $lobbyPrinter->id,
        'cost_centre_id' => $costCentre->id,
        'copies' => 1,
    ])->assertSessionHasErrors('printer_id');

    $blockedAudit = $dbContext->runAs(
        $wgCdr,
        static fn (): bool => DB::table('audit_events')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'print.blocked_policy_violation')
            ->where('resource_id', $secretOps->id)
            ->exists()
    );
    expect($blockedAudit)->toBeTrue();

    // 2. Quota enforcement: tiny quota blocks oversized job
    $dbContext->runAs($wgCdr, static fn () => $costCentre->update(['monthly_page_quota' => 2, 'pages_used_month' => 0]));
    $this->post("/documents/{$secretOps->id}/print", [
        'printer_id' => $opsPrinter->id,
        'cost_centre_id' => $costCentre->id,
        'copies' => 4,
    ])->assertSessionHasErrors('printer_id');

    // 3. Separate 'print' right: Corporal with perm_view=true but perm_print=false cannot print
    $internalLevel = ClearanceLevel::query()->where('tenant_id', $tenant->id)->where('rank', 1)->firstOrFail();
    $restrictedDoc = $dbContext->runAs($wgCdr, static function () use ($tenant, $wgCdr, $cpl, $internalLevel, $publicRoster): Document {
        $cab = Cabinet::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $v = $publicRoster->currentVersion;

        /** @var Document $d */
        $d = Document::query()->create([
            'tenant_id' => $tenant->id,
            'cabinet_id' => $cab->id,
            'org_unit_id' => $wgCdr->org_unit_id,
            'owner_id' => $wgCdr->id,
            'classification_level_id' => $internalLevel->id,
            'classification_rank' => 1,
            'doc_number' => 'AFZ/TEST/PRINT-RIGHT',
            'title' => 'View Only No Print Notice',
            'status' => DocumentStatus::Active,
            'unit_wide_readable' => false,
        ]);
        if ($v !== null) {
            $copiedV = $v->replicate();
            $copiedV->document_id = $d->id;
            $copiedV->save();
            $d->update(['current_version_id' => $copiedV->id]);
        }

        DocumentAclGrant::query()->create([
            'tenant_id' => $tenant->id,
            'document_id' => $d->id,
            'principal_type' => PrincipalType::User,
            'principal_id' => $cpl->id,
            'perm_view' => true,
            'perm_download' => false,
            'perm_print' => false,
            'granted_by' => $wgCdr->id,
        ]);

        return $d;
    });

    $this->signIn($cpl);
    $this->postJson("/documents/{$restrictedDoc->id}/print", [
        'printer_id' => $lobbyPrinter->id,
    ])->assertStatus(422);
});

it('stamps mandatory diagonal watermarks on held print jobs, releases on valid PIN or RFID, and locks after 3 invalid PINs', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $secretOps = $dbContext->runAs(
        $wgCdr,
        static fn (): Document => Document::query()->where('doc_number', 'AFZ/OPS/2026/088')->firstOrFail()
    );
    [$opsPrinter, $costCentre] = $dbContext->runAs($wgCdr, static function () use ($tenant): array {
        return [
            Printer::query()->where('tenant_id', $tenant->id)->where('cups_queue_name', 'afz-ops-secret')->firstOrFail(),
            CostCentre::query()->where('tenant_id', $tenant->id)->where('code', 'CC-HQ-OPS')->firstOrFail(),
        ];
    });

    $this->signIn($wgCdr);

    // Job 1: Held securely with 6-digit PIN + diagonal watermark verification
    $res1 = $this->postJson("/documents/{$secretOps->id}/print", [
        'printer_id' => $opsPrinter->id,
        'cost_centre_id' => $costCentre->id,
        'copies' => 2,
    ])->assertCreated();

    $job1Id = (string) $res1->json('job.id');
    $validPin1 = (string) $res1->json('release_pin');
    expect(strlen($validPin1))->toBe(6)
        ->and($res1->json('job.status'))->toBe(PrintJobStatus::Held->value)
        ->and((string) $res1->json('job.watermark_stamp_text'))->toContain('AFZ-0142');

    // Preview stamped spool PDF and confirm diagonal watermark text is baked inside PDF stream
    $previewResp = $this->get("/print-scan/jobs/{$job1Id}/preview")->assertOk();
    $pdfBytes = $previewResp->getContent();
    expect(is_string($pdfBytes) && str_starts_with($pdfBytes, '%PDF-'))->toBeTrue();

    $parsedText = (new Parser)->parseContent((string) $pdfBytes)->getText();
    expect($parsedText)->toContain('AFZ-0142')
        ->and($parsedText)->toContain('AUTHORIZED PRINT COPY');

    // 3 invalid PIN attempts LOCK the print job permanently
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $this->postJson("/print-scan/jobs/{$job1Id}/release", ['pin' => '000000'])
            ->assertStatus(422)
            ->assertJson(['status' => PrintJobStatus::Held->value, 'pin_attempts' => $attempt]);
    }

    $this->postJson("/print-scan/jobs/{$job1Id}/release", ['pin' => '000000'])
        ->assertStatus(422)
        ->assertJson(['status' => PrintJobStatus::Locked->value, 'pin_attempts' => 3]);

    // Subsequent attempt even with the previously valid PIN MUST fail once locked
    $this->postJson("/print-scan/jobs/{$job1Id}/release", ['pin' => $validPin1])
        ->assertStatus(422)
        ->assertJson(['status' => PrintJobStatus::Locked->value]);

    // Job 2: Valid PIN releases held job and bills cost centre quota
    $res2 = $this->postJson("/documents/{$secretOps->id}/print", [
        'printer_id' => $opsPrinter->id,
        'cost_centre_id' => $costCentre->id,
        'copies' => 2,
    ])->assertCreated();

    $job2Id = (string) $res2->json('job.id');
    $validPin2 = (string) $res2->json('release_pin');

    $relRes = $this->postJson("/print-scan/jobs/{$job2Id}/release", ['pin' => $validPin2])
        ->assertOk();

    expect($relRes->json('job.status'))->toBe(PrintJobStatus::Printed->value)
        ->and((string) $relRes->json('job.cups_job_id'))->not->toBeEmpty();

    $usedPages = $dbContext->runAs($wgCdr, static fn (): int => $costCentre->refresh()->pages_used_month);
    expect($usedPages)->toBeGreaterThanOrEqual(2);

    // Job 3: RFID/NFC badge tap releases user's eligible held job cleanly
    $res3 = $this->postJson("/documents/{$secretOps->id}/print", [
        'printer_id' => $opsPrinter->id,
        'cost_centre_id' => $costCentre->id,
        'copies' => 1,
    ])->assertCreated();
    $job3Id = (string) $res3->json('job.id');

    $this->post('/print-scan/release-rfid', [
        'rfid_badge_uid' => 'BADGE-AFZ-0142',
        'printer_id' => $opsPrinter->id,
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $job3Status = $dbContext->runAs($wgCdr, static fn (): string => PrintJob::query()->findOrFail($job3Id)->status->value);
    expect($job3Status)->toBe(PrintJobStatus::Printed->value);
});

it('splits multi-doc rasterized scanned PDFs accurately at Barcode Separator Sheets and polls hot folders and mail-in .eml', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);
    $bridge = app(PrintScanBridgeService::class);

    $restrictedLevel = ClearanceLevel::query()->where('tenant_id', $tenant->id)->where('rank', 1)->firstOrFail();
    $cab = $dbContext->runAs($wgCdr, static fn (): Cabinet => Cabinet::query()->where('tenant_id', $tenant->id)->firstOrFail());

    $this->signIn($wgCdr);

    // 1. Download Separator Sheet PDF via HTTP endpoint & generate second separator
    $sepResp = $this->get('/print-scan/separator-sheet?doc_type=invoice&title=ZIMRA%20Avionics%20Batch%20Invoice&cabinet_code='.urlencode($cab->name).'&classification_name=Restricted')
        ->assertOk();
    $sep1Pdf = (string) $sepResp->getContent();
    $sep2Pdf = $bridge->generateSeparatorPdf('letter', 'Squadron Dispatch Directive', $cab->name, 'Restricted');

    // Rasterize BOTH separators to pure text-free PNG images interleaved with 2 real content PDFs!
    $sep1Path = tempnam(sys_get_temp_dir(), 'sep1_').'.pdf';
    $sep2Path = tempnam(sys_get_temp_dir(), 'sep2_').'.pdf';
    $combinedPath = tempnam(sys_get_temp_dir(), 'comb_').'.pdf';
    file_put_contents($sep1Path, $sep1Pdf);
    file_put_contents($sep2Path, $sep2Pdf);

    $pythonBin = config()->string('betterdoc.pki.python_bin', 'python');
    $pyScript = <<<'PY'
import sys, pymupdf
s1 = pymupdf.open(sys.argv[1])
s2 = pymupdf.open(sys.argv[2])
out = pymupdf.open()

# Page 1: Pure rasterized image of Separator 1 (NO text layer at all!)
p1 = out.new_page(width=595, height=842)
p1.insert_image(p1.rect, stream=s1[0].get_pixmap(dpi=150).tobytes("png"))

# Page 2: Content for Invoice 1
c1 = out.new_page(width=595, height=842)
c1.insert_text((50, 100), "TAX INVOICE\nVendor: Harare Aviation Spares Ltd\nInvoice Number: INV-SCN-772\nCurrency: USD\nTotal Amount: 9450.00")

# Page 3: Pure rasterized image of Separator 2 (NO text layer!)
p3 = out.new_page(width=595, height=842)
p3.insert_image(p3.rect, stream=s2[0].get_pixmap(dpi=150).tobytes("png"))

# Pages 4-5: Content for Directive Letter 2
c2 = out.new_page(width=595, height=842)
c2.insert_text((50, 100), "Squadron Dispatch Directive page 1 compliance brief.")
c3 = out.new_page(width=595, height=842)
c3.insert_text((50, 100), "Squadron Dispatch Directive page 2 annex.")

out.save(sys.argv[3])
PY;

    try {
        $proc = Process::timeout(20)->run([$pythonBin, '-c', $pyScript, $sep1Path, $sep2Path, $combinedPath]);
        expect($proc->successful())->toBeTrue($proc->errorOutput());

        $combinedBytes = (string) file_get_contents($combinedPath);
        $upload = UploadedFile::fake()->createWithContent('feeder-stack.pdf', $combinedBytes);

        $batchRes = $this->postJson('/print-scan/scan-batches', [
            'file' => $upload,
            'cabinet_id' => $cab->id,
            'classification_level_id' => $restrictedLevel->id,
            'auto_split_barcode' => true,
            'auto_deskew' => true,
            'auto_idp' => false,
        ])->assertCreated();

        expect($batchRes->json('batch.split_documents_count'))->toBe(2)
            ->and($batchRes->json('batch.report_json.separators_found'))->toBe(2);

        /** @var list<string> $createdIds */
        $createdIds = (array) $batchRes->json('batch.report_json.created_doc_ids');
        expect($createdIds)->toHaveCount(2);

        [$doc1, $doc2] = $dbContext->runAs($wgCdr, static fn (): array => [
            Document::query()->with('currentVersion')->findOrFail($createdIds[0]),
            Document::query()->with('currentVersion')->findOrFail($createdIds[1]),
        ]);

        expect($doc1->title)->toBe('ZIMRA Avionics Batch Invoice')
            ->and($doc1->currentVersion?->page_count)->toBe(1)
            ->and($doc2->title)->toBe('Squadron Dispatch Directive')
            ->and($doc2->currentVersion?->page_count)->toBe(2);

        // Separator 1 had doc_type=invoice -> automatic IDP extraction populated!
        $idpCount = $dbContext->runAs(
            $wgCdr,
            static fn (): int => IdpExtraction::query()->where('document_id', $doc1->id)->count()
        );
        expect($idpCount)->toBe(1);
    } finally {
        @unlink($sep1Path);
        @unlink($sep2Path);
        @unlink($combinedPath);
    }

    // 2. Test Hot Folder & Local Mail-In .eml polling cleanly in isolated temp subdirs
    $hfSub = 'test-hf-'.substr(bin2hex(random_bytes(4)), 0, 8);
    $mailSub = 'test-mail-'.substr(bin2hex(random_bytes(4)), 0, 8);
    $hfRoot = rtrim(config()->string('betterdoc.print_scan.hot_folder_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$hfSub;
    $mailRoot = rtrim(config()->string('betterdoc.print_scan.mail_in_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$mailSub;
    mkdir($hfRoot, 0750, true);
    mkdir($mailRoot, 0750, true);

    try {
        file_put_contents($hfRoot.DIRECTORY_SEPARATOR.'hotfolder-scan.pdf', $sep2Pdf);

        $b64Att = chunk_split(base64_encode($sep2Pdf));
        $eml = implode("\r\n", [
            'From: records@afz.mil.zw',
            'Subject: Emailed Maintenance Advice',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="SENTBOUND"',
            '',
            '--SENTBOUND',
            'Content-Type: text/plain',
            '',
            'Attached PDF scan.',
            '--SENTBOUND',
            'Content-Type: application/pdf; name="emailed-advice.pdf"',
            'Content-Disposition: attachment; filename="emailed-advice.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            $b64Att,
            '--SENTBOUND--',
        ]);
        file_put_contents($mailRoot.DIRECTORY_SEPARATOR.'capture.eml', $eml);

        $hfSource = $dbContext->runAs($wgCdr, static fn (): ScanSource => ScanSource::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Temp Test Hot Folder',
            'source_type' => ScanSourceType::HotFolder,
            'endpoint_or_path' => $hfSub,
            'target_cabinet_id' => $cab->id,
            'default_classification_level_id' => $restrictedLevel->id,
            'auto_ocr' => true,
            'auto_split_barcode' => false,
            'auto_deskew' => false,
            'is_active' => true,
        ]));

        $mailSource = $dbContext->runAs($wgCdr, static fn (): ScanSource => ScanSource::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Temp Test Mail-In',
            'source_type' => ScanSourceType::MailIn,
            'endpoint_or_path' => $mailSub,
            'target_cabinet_id' => $cab->id,
            'default_classification_level_id' => $restrictedLevel->id,
            'auto_ocr' => true,
            'auto_split_barcode' => false,
            'auto_deskew' => false,
            'is_active' => true,
        ]));

        $this->post("/print-scan/scan-sources/{$hfSource->id}/poll")->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post("/print-scan/scan-sources/{$mailSource->id}/poll")->assertRedirect()->assertSessionDoesntHaveErrors();

        $mailIngested = $dbContext->runAs(
            $wgCdr,
            static fn (): bool => ScanBatch::query()->where('tenant_id', $tenant->id)->where('source_channel', 'mail_in')->exists()
        );
        expect($mailIngested)->toBeTrue();

        $this->artisan('betterdoc:scan:poll', ['--tenant' => $tenant->id])->assertExitCode(0);

        // 3. Test PWA Mobile Camera PNG Auto-Deskew/Border Crop & LAN eSCL/AirScan Polling
        $pngPath = tempnam(sys_get_temp_dir(), 'pwa_cam_').'.png';
        $imgScript = <<<'PY'
import sys
from PIL import Image, ImageDraw
im = Image.new("RGB", (600, 800), (20, 20, 20))
draw = ImageDraw.Draw(im)
draw.rectangle([40, 40, 560, 760], fill=(255, 255, 255))
for y in range(120, 620, 30):
    draw.rectangle([80, y, 500, y + 6], fill=(30, 30, 30))
im.rotate(2.0, fillcolor=(15, 15, 15)).save(sys.argv[1], "PNG")
PY;
        try {
            $imgProc = Process::timeout(15)->run([$pythonBin, '-c', $imgScript, $pngPath]);
            expect($imgProc->successful())->toBeTrue($imgProc->errorOutput());

            $camUpload = UploadedFile::fake()->createWithContent('mobile-camera-capture.png', (string) file_get_contents($pngPath));
            $camRes = $this->postJson('/print-scan/scan-batches', [
                'file' => $camUpload,
                'cabinet_id' => $cab->id,
                'classification_level_id' => $restrictedLevel->id,
                'auto_split_barcode' => false,
                'auto_deskew' => true,
                'source_channel' => 'pwa_camera',
                'title_prefix' => 'PWA Mobile Field Capture',
            ])->assertCreated();
            expect($camRes->json('batch.source_channel'))->toBe('pwa_camera')
                ->and($camRes->json('batch.split_documents_count'))->toBe(1);
        } finally {
            @unlink($pngPath);
        }

        $esclContentPdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n4 0 obj << /Length 46 >> stream\nBT /F1 12 Tf 40 740 Td (ESCL AIRSCAN DOC) Tj ET\nendstream endobj\n5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\nxref\n0 6\n0000000000 65535 f \ntrailer << /Size 6 /Root 1 0 R >>\nstartxref\n0\n%%EOF";
        Http::fake([
            'http://localhost:8085/eSCL/ScanJobs/NextDocument' => Http::response($esclContentPdf, 200, ['Content-Type' => 'application/pdf']),
        ]);
        $esclSource = $dbContext->runAs(
            $wgCdr,
            static fn (): ScanSource => ScanSource::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_type', ScanSourceType::EsclAirscan->value)
                ->firstOrFail()
        );
        $this->post("/print-scan/scan-sources/{$esclSource->id}/poll")->assertRedirect()->assertSessionDoesntHaveErrors();
        $esclIngested = $dbContext->runAs(
            $wgCdr,
            static fn (): bool => ScanBatch::query()->where('tenant_id', $tenant->id)->where('source_channel', 'escl_airscan')->exists()
        );
        expect($esclIngested)->toBeTrue();
    } finally {
        @unlink($hfRoot.DIRECTORY_SEPARATOR.'hotfolder-scan.pdf');
        @unlink($hfRoot.DIRECTORY_SEPARATOR.'processed'.DIRECTORY_SEPARATOR.'hotfolder-scan.pdf');
        @rmdir($hfRoot.DIRECTORY_SEPARATOR.'processed');
        @rmdir($hfRoot);
        @unlink($mailRoot.DIRECTORY_SEPARATOR.'capture.eml');
        @unlink($mailRoot.DIRECTORY_SEPARATOR.'processed'.DIRECTORY_SEPARATOR.'capture.eml');
        @rmdir($mailRoot.DIRECTORY_SEPARATOR.'processed');
        @rmdir($mailRoot);
    }
});

it('pairs BetterDoc Local Agent via one-time code, ingests TWAIN/WIA workstation scans over bearer token, and honors revocation', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'afz-defence')->firstOrFail();
    /** @var User $wgCdr */
    $wgCdr = User::query()->where('email', 'wgcdr.moyo@afz.mil.zw')->firstOrFail();
    $dbContext = app(DbSecurityContext::class);

    $cab = $dbContext->runAs($wgCdr, static fn (): Cabinet => Cabinet::query()->where('tenant_id', $tenant->id)->firstOrFail());
    $restrictedLevel = ClearanceLevel::query()->where('tenant_id', $tenant->id)->where('rank', 1)->firstOrFail();

    $this->signIn($wgCdr);

    // Download workstation Python bridge script cleanly
    $scriptResp = $this->get('/print-scan/agents/download-script')->assertOk();
    expect((string) $scriptResp->getContent())->toContain('127.0.0.1');

    // Step 1: User initiates pairing from web UI
    $pairInit = $this->postJson('/print-scan/agents/pair-init', [
        'workstation_name' => 'OPS-DESK-PC-04',
    ])->assertCreated();

    $agentId = (string) $pairInit->json('agent.id');
    $pairingCode = (string) $pairInit->json('pairing_code');

    // Step 2: Workstation Python daemon exchanges pairing code cleanly without web session
    $this->post('/logout');

    $pairComplete = $this->postJson('/local-agent/pair', [
        'agent_id' => $agentId,
        'pairing_code' => $pairingCode,
        'agent_version' => '1.2.0',
        'capabilities' => [
            'wia_scanners' => ['Canon DR-C225 USB'],
            'pkcs11_tokens' => ['SafeNet eToken 5110'],
        ],
    ])->assertOk();

    $bearerToken = (string) $pairComplete->json('bearer_token');
    expect($bearerToken)->toStartWith('sent_agt_');

    // Step 3: Local agent posts TWAIN/WIA scanned PDF using bearer token
    $samplePdf = app(PrintScanBridgeService::class)->generateSeparatorPdf('memo', 'Station Scan Memo', $cab->name, 'Restricted');
    // Build a tiny non-separator single page PDF via minimal PDF bytes
    $contentPdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n4 0 obj << /Length 44 >> stream\nBT /F1 12 Tf 40 740 Td (USB WIA SCAN) Tj ET\nendstream endobj\n5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\nxref\n0 6\n0000000000 65535 f \ntrailer << /Size 6 /Root 1 0 R >>\nstartxref\n0\n%%EOF";
    unset($samplePdf);

    $scanRes = $this->withHeaders([
        'X-BetterDoc-Agent-Token' => $bearerToken,
    ])->postJson('/local-agent/scan', [
        'cabinet_id' => $cab->id,
        'classification_level_id' => $restrictedLevel->id,
        'filename' => 'usb-scanner-page.pdf',
        'file_b64' => base64_encode($contentPdf),
    ])->assertCreated();

    expect($scanRes->json('batch.source_channel'))->toBe('twain_wia')
        ->and($scanRes->json('batch.split_documents_count'))->toBe(1);

    // Step 4: Revoke agent cleanly -> future token attempts return 401
    $this->signIn($wgCdr);
    $this->post("/print-scan/agents/{$agentId}/revoke")->assertRedirect();

    $this->withHeaders([
        'X-BetterDoc-Agent-Token' => $bearerToken,
    ])->postJson('/local-agent/scan', [
        'cabinet_id' => $cab->id,
        'classification_level_id' => $restrictedLevel->id,
        'filename' => 'revoked-attempt.pdf',
        'file_b64' => base64_encode($contentPdf),
    ])->assertStatus(401);
});
