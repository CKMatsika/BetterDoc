class AuthSession {
  const AuthSession({
    required this.baseUrl,
    required this.token,
    required this.userId,
    required this.userName,
    required this.userEmail,
    required this.clearanceRank,
    required this.tenantName,
  });

  final String baseUrl;
  final String token;
  final String userId;
  final String userName;
  final String userEmail;
  final int clearanceRank;
  final String tenantName;

  Map<String, Object?> toJson() => {
        'base_url': baseUrl,
        'token': token,
        'user_id': userId,
        'user_name': userName,
        'user_email': userEmail,
        'clearance_rank': clearanceRank,
        'tenant_name': tenantName,
      };

  factory AuthSession.fromJson(Map<String, Object?> json) => AuthSession(
        baseUrl: (json['base_url'] as String? ?? '').trim(),
        token: (json['token'] as String? ?? '').trim(),
        userId: (json['user_id'] as String? ?? '').trim(),
        userName: (json['user_name'] as String? ?? 'User').trim(),
        userEmail: (json['user_email'] as String? ?? '').trim(),
        clearanceRank: (json['clearance_rank'] as num?)?.toInt() ?? 1,
        tenantName: (json['tenant_name'] as String? ?? 'BetterDoc DMS').trim(),
      );
}

class CabinetOption {
  const CabinetOption({required this.id, required this.name});

  final String id;
  final String name;

  factory CabinetOption.fromJson(Map<String, Object?> json) => CabinetOption(
        id: json['id'] as String? ?? '',
        name: json['name'] as String? ?? 'General',
      );
}

class ClearanceOption {
  const ClearanceOption({
    required this.id,
    required this.rank,
    required this.code,
    required this.name,
  });

  final String id;
  final int rank;
  final String code;
  final String name;

  factory ClearanceOption.fromJson(Map<String, Object?> json) => ClearanceOption(
        id: json['id'] as String? ?? '',
        rank: (json['rank'] as num?)?.toInt() ?? 0,
        code: json['code'] as String? ?? 'INT',
        name: json['name'] as String? ?? 'Internal',
      );
}

class VendorOption {
  const VendorOption({
    required this.id,
    required this.code,
    required this.name,
    required this.currency,
  });

  final String id;
  final String code;
  final String name;
  final String currency;

  factory VendorOption.fromJson(Map<String, Object?> json) => VendorOption(
        id: json['id'] as String? ?? '',
        code: json['code'] as String? ?? '',
        name: json['name'] as String? ?? 'Vendor',
        currency: json['currency'] as String? ?? 'USD',
      );
}

class ErpTargetOption {
  const ErpTargetOption({
    required this.targetType,
    required this.targetId,
    required this.name,
    required this.systemType,
    required this.mode,
    required this.status,
  });

  final String targetType;
  final String targetId;
  final String name;
  final String systemType;
  final String mode;
  final String status;

  String get selectionKey => '$targetType:$targetId';

  factory ErpTargetOption.fromJson(Map<String, Object?> json) => ErpTargetOption(
        targetType: json['target_type'] as String? ?? 'none',
        targetId: json['target_id'] as String? ?? '',
        name: json['name'] as String? ?? 'Accounting ERP',
        systemType: json['system_type'] as String? ?? '',
        mode: json['mode'] as String? ?? 'cloud_api',
        status: json['status'] as String? ?? 'active',
      );
}

class BootstrapData {
  const BootstrapData({
    required this.cabinets,
    required this.clearances,
    required this.vendors,
    required this.erpTargets,
  });

  final List<CabinetOption> cabinets;
  final List<ClearanceOption> clearances;
  final List<VendorOption> vendors;
  final List<ErpTargetOption> erpTargets;

  factory BootstrapData.fromJson(Map<String, Object?> json) {
    List<Map<String, Object?>> listOf(String key) =>
        (json[key] as List<Object?>? ?? const [])
            .whereType<Map<Object?, Object?>>()
            .map((e) => e.cast<String, Object?>())
            .toList();

    return BootstrapData(
      cabinets: listOf('cabinets').map(CabinetOption.fromJson).toList(),
      clearances: listOf('allowed_clearances').map(ClearanceOption.fromJson).toList(),
      vendors: listOf('vendors').map(VendorOption.fromJson).toList(),
      erpTargets: listOf('erp_targets').map(ErpTargetOption.fromJson).toList(),
    );
  }
}

class MobileDocumentItem {
  const MobileDocumentItem({
    required this.id,
    required this.docNumber,
    required this.title,
    required this.status,
    required this.isWorm,
    required this.cabinetName,
    required this.classificationName,
    this.policyNumber,
    this.claimNumber,
    this.erpSummary,
  });

  final String id;
  final String docNumber;
  final String title;
  final String status;
  final bool isWorm;
  final String cabinetName;
  final String classificationName;
  final String? policyNumber;
  final String? claimNumber;
  final String? erpSummary;

  factory MobileDocumentItem.fromJson(Map<String, Object?> json) {
    final meta = json['metadata'] as Map<Object?, Object?>?;
    final erp = (json['mobile_erp_processing'] ?? meta?['mobile_erp_processing'])
        as Map<Object?, Object?>?;
    String? erpSummary;
    if (erp != null) {
      final target = erp['erp_target_name'] as String? ?? 'ERP';
      final st = erp['erp_sync_status'] as String? ?? '';
      final inv = erp['invoice_number'] as String? ?? '';
      erpSummary = '$target ($st) · $inv';
    }

    return MobileDocumentItem(
      id: json['id'] as String? ?? '',
      docNumber: json['doc_number'] as String? ?? 'DOC',
      title: json['title'] as String? ?? 'Untitled Document',
      status: json['status'] as String? ?? 'active',
      isWorm: json['is_worm'] as bool? ?? false,
      cabinetName: json['cabinet_name'] as String? ?? 'Vault',
      classificationName: json['classification_name'] as String? ?? 'Internal',
      policyNumber: json['policy_number'] as String?,
      claimNumber: json['claim_number'] as String?,
      erpSummary: erpSummary,
    );
  }
}

class SuggestedInvoice {
  const SuggestedInvoice({
    this.vendorId,
    required this.vendorName,
    required this.invoiceNumber,
    required this.invoiceDate,
    required this.currency,
    required this.subtotalCents,
    required this.vatCents,
    required this.totalCents,
    required this.description,
  });

  final String? vendorId;
  final String vendorName;
  final String invoiceNumber;
  final String invoiceDate;
  final String currency;
  final int subtotalCents;
  final int vatCents;
  final int totalCents;
  final String description;

  factory SuggestedInvoice.fromJson(Map<String, Object?> json) => SuggestedInvoice(
        vendorId: json['vendor_id'] as String?,
        vendorName: json['vendor_name'] as String? ?? 'Supplier',
        invoiceNumber: json['invoice_number'] as String? ?? '',
        invoiceDate: json['invoice_date'] as String? ?? DateTime.now().toIso8601String().substring(0, 10),
        currency: json['currency'] as String? ?? 'USD',
        subtotalCents: (json['subtotal_cents'] as num?)?.toInt() ?? 0,
        vatCents: (json['vat_cents'] as num?)?.toInt() ?? 0,
        totalCents: (json['total_cents'] as num?)?.toInt() ?? 0,
        description: json['description'] as String? ?? 'Mobile Captured Invoice',
      );
}

class CaptureResult {
  const CaptureResult({
    required this.document,
    required this.isInvoice,
    required this.detectedDocType,
    required this.confidence,
    this.suggestedInvoice,
    required this.erpTargets,
  });

  final MobileDocumentItem document;
  final bool isInvoice;
  final String detectedDocType;
  final double confidence;
  final SuggestedInvoice? suggestedInvoice;
  final List<ErpTargetOption> erpTargets;

  factory CaptureResult.fromJson(Map<String, Object?> json) {
    final docMap = (json['document'] as Map<Object?, Object?>? ?? const {}).cast<String, Object?>();
    final extMap = (json['extraction'] as Map<Object?, Object?>?)?.cast<String, Object?>();
    final invMap = (json['suggested_invoice'] as Map<Object?, Object?>?)?.cast<String, Object?>();
    final targets = (json['erp_targets'] as List<Object?>? ?? const [])
        .whereType<Map<Object?, Object?>>()
        .map((e) => ErpTargetOption.fromJson(e.cast<String, Object?>()))
        .toList();

    return CaptureResult(
      document: MobileDocumentItem.fromJson(docMap),
      isInvoice: json['is_invoice'] as bool? ?? false,
      detectedDocType: extMap?['detected_doc_type'] as String? ?? 'document',
      confidence: (extMap?['overall_confidence'] as num?)?.toDouble() ?? 0.0,
      suggestedInvoice: invMap != null ? SuggestedInvoice.fromJson(invMap) : null,
      erpTargets: targets,
    );
  }
}

class ErpProcessResult {
  const ErpProcessResult({
    required this.document,
    required this.invoiceNumber,
    required this.vendorName,
    required this.currency,
    required this.totalCents,
    required this.whtApplicable,
    required this.whtCents,
    required this.netPayableCents,
    required this.erpTargetName,
    required this.erpSyncStatus,
    required this.erpMessage,
  });

  final MobileDocumentItem document;
  final String invoiceNumber;
  final String vendorName;
  final String currency;
  final int totalCents;
  final bool whtApplicable;
  final int whtCents;
  final int netPayableCents;
  final String erpTargetName;
  final String erpSyncStatus;
  final String erpMessage;

  factory ErpProcessResult.fromJson(Map<String, Object?> json) {
    final docMap = (json['document'] as Map<Object?, Object?>? ?? const {}).cast<String, Object?>();
    final apMap = (json['ap_invoice'] as Map<Object?, Object?>? ?? const {}).cast<String, Object?>();
    final erpMap = (json['erp_result'] as Map<Object?, Object?>? ?? const {}).cast<String, Object?>();

    return ErpProcessResult(
      document: MobileDocumentItem.fromJson(docMap),
      invoiceNumber: apMap['invoice_number'] as String? ?? '',
      vendorName: apMap['vendor_name'] as String? ?? '',
      currency: apMap['currency'] as String? ?? 'USD',
      totalCents: (apMap['total_cents'] as num?)?.toInt() ?? 0,
      whtApplicable: apMap['wht_applicable'] as bool? ?? false,
      whtCents: (apMap['wht_cents'] as num?)?.toInt() ?? 0,
      netPayableCents: (apMap['net_payable_cents'] as num?)?.toInt() ?? 0,
      erpTargetName: erpMap['target_name'] as String? ?? 'Accounting ERP',
      erpSyncStatus: erpMap['status'] as String? ?? 'completed',
      erpMessage: erpMap['message'] as String? ?? 'Processed successfully.',
    );
  }
}

class OfflineQueuedCapture {
  const OfflineQueuedCapture({
    required this.id,
    required this.filePath,
    required this.filename,
    required this.title,
    required this.cabinetId,
    required this.clearanceLevelId,
    required this.forceInvoice,
    required this.queuedAtIso,
  });

  final String id;
  final String filePath;
  final String filename;
  final String title;
  final String cabinetId;
  final String clearanceLevelId;
  final bool forceInvoice;
  final String queuedAtIso;

  Map<String, Object?> toJson() => {
        'id': id,
        'file_path': filePath,
        'filename': filename,
        'title': title,
        'cabinet_id': cabinetId,
        'clearance_level_id': clearanceLevelId,
        'force_invoice': forceInvoice,
        'queued_at': queuedAtIso,
      };

  factory OfflineQueuedCapture.fromJson(Map<String, Object?> json) => OfflineQueuedCapture(
        id: json['id'] as String? ?? '',
        filePath: json['file_path'] as String? ?? '',
        filename: json['filename'] as String? ?? 'capture.jpg',
        title: json['title'] as String? ?? 'Offline Capture',
        cabinetId: json['cabinet_id'] as String? ?? '',
        clearanceLevelId: json['clearance_level_id'] as String? ?? '',
        forceInvoice: json['force_invoice'] as bool? ?? false,
        queuedAtIso: json['queued_at'] as String? ?? DateTime.now().toIso8601String(),
      );
}
