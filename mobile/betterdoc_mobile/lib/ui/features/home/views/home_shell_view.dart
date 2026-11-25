import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../../../domain/models/betterdoc_models.dart';
import '../../../core/betterdoc_theme.dart';
import '../view_models/home_view_model.dart';

class HomeShellView extends StatefulWidget {
  const HomeShellView({super.key, required this.viewModel});

  final HomeViewModel viewModel;

  @override
  State<HomeShellView> createState() => _HomeShellViewState();
}

class _HomeShellViewState extends State<HomeShellView> {
  final _picker = ImagePicker();
  int _tabIndex = 0;

  // Capture form state
  Uint8List? _pickedBytes;
  String _pickedFilename = 'capture.jpg';
  final _titleCtrl = TextEditingController();
  String? _selectedCabinetId;
  String? _selectedClearanceId;
  bool _forceInvoice = false;

  // Invoice-to-ERP confirmation controllers
  final _vendorNameCtrl = TextEditingController();
  final _invoiceNoCtrl = TextEditingController();
  final _invoiceDateCtrl = TextEditingController();
  final _subtotalCtrl = TextEditingController();
  final _vatCtrl = TextEditingController();
  final _totalCtrl = TextEditingController();
  String _currency = 'USD';
  String _erpSelection = 'none';
  final bool _autoApprove = true;
  bool _archiveDoc = true;
  bool _wormLock = false;
  String? _hydratedDocId;

  // Vault search
  final _searchCtrl = TextEditingController();

  @override
  void dispose() {
    _titleCtrl.dispose();
    _vendorNameCtrl.dispose();
    _invoiceNoCtrl.dispose();
    _invoiceDateCtrl.dispose();
    _subtotalCtrl.dispose();
    _vatCtrl.dispose();
    _totalCtrl.dispose();
    _searchCtrl.dispose();
    super.dispose();
  }

  void _syncDefaultsFromBootstrap(BootstrapData? boot) {
    if (boot == null) {
      return;
    }
    if (_selectedCabinetId == null && boot.cabinets.isNotEmpty) {
      _selectedCabinetId = boot.cabinets.first.id;
    }
    if (_selectedClearanceId == null && boot.clearances.isNotEmpty) {
      _selectedClearanceId = boot.clearances.first.id;
    }
  }

  void _hydrateInvoiceForm(CaptureResult capture) {
    if (_hydratedDocId == capture.document.id) {
      return;
    }
    _hydratedDocId = capture.document.id;
    final inv = capture.suggestedInvoice;
    if (inv != null) {
      _vendorNameCtrl.text = inv.vendorName;
      _invoiceNoCtrl.text = inv.invoiceNumber;
      _invoiceDateCtrl.text = inv.invoiceDate;
      _currency = inv.currency;
      _subtotalCtrl.text = (inv.subtotalCents / 100).toStringAsFixed(2);
      _vatCtrl.text = (inv.vatCents / 100).toStringAsFixed(2);
      _totalCtrl.text = (inv.totalCents / 100).toStringAsFixed(2);
    }
    _erpSelection = capture.erpTargets.isNotEmpty ? capture.erpTargets.first.selectionKey : 'none';
  }

  Future<void> _pickImage(ImageSource source) async {
    final xfile = await _picker.pickImage(source: source, imageQuality: 88);
    if (xfile == null) {
      return;
    }
    final bytes = await xfile.readAsBytes();
    setState(() {
      _pickedBytes = bytes;
      _pickedFilename = xfile.name;
      if (_titleCtrl.text.trim().isEmpty) {
        _titleCtrl.text = xfile.name.replaceAll(RegExp(r'\.[^.]+$'), '');
      }
    });
  }

  int _moneyToCents(String raw) {
    final val = double.tryParse(raw.replaceAll(RegExp(r'[^0-9.]'), '')) ?? 0.0;
    return (val * 100).round();
  }

  Future<void> _submitCapture() async {
    final bytes = _pickedBytes;
    final cabId = _selectedCabinetId;
    final clrId = _selectedClearanceId;
    if (bytes == null || cabId == null || clrId == null) {
      return;
    }

    await widget.viewModel.submitCapture(
      bytes: bytes,
      filename: _pickedFilename,
      cabinetId: cabId,
      clearanceLevelId: clrId,
      title: _titleCtrl.text.trim(),
      forceInvoice: _forceInvoice,
    );
  }

  Future<void> _submitInvoiceToErp() async {
    String targetType = 'none';
    String? targetId;
    if (_erpSelection.contains(':')) {
      final parts = _erpSelection.split(':');
      targetType = parts[0];
      targetId = parts[1];
    }

    await widget.viewModel.processInvoiceToErp({
      'vendor_name': _vendorNameCtrl.text.trim(),
      'invoice_number': _invoiceNoCtrl.text.trim(),
      'invoice_date': _invoiceDateCtrl.text.trim(),
      'currency': _currency,
      'subtotal_cents': _moneyToCents(_subtotalCtrl.text),
      'vat_cents': _moneyToCents(_vatCtrl.text),
      'total_cents': _moneyToCents(_totalCtrl.text),
      'auto_approve': _autoApprove,
      'erp_target_type': targetType,
      'erp_target_id': targetId,
      'archive_document': _archiveDoc,
      'apply_worm_lock': _wormLock,
    });
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.viewModel,
      builder: (context, _) {
        final vm = widget.viewModel;
        final session = vm.session!;
        _syncDefaultsFromBootstrap(vm.bootstrap);
        if (vm.captureResult != null) {
          _hydrateInvoiceForm(vm.captureResult!);
        }

        return Scaffold(
          appBar: AppBar(
            title: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(session.tenantName, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700)),
                Text(
                  '${session.userName} · Clearance L${session.clearanceRank}',
                  style: const TextStyle(fontSize: 11, color: Colors.white70),
                ),
              ],
            ),
            actions: [
              IconButton(
                onPressed: vm.isBusy ? null : () => vm.refreshWorkspace(query: _searchCtrl.text),
                icon: const Icon(Icons.refresh_rounded),
                tooltip: 'Refresh',
              ),
              IconButton(
                onPressed: vm.signOut,
                icon: const Icon(Icons.logout_rounded),
                tooltip: 'Sign Out',
              ),
            ],
          ),
          body: IndexedStack(
            index: _tabIndex,
            children: [
              _buildSnapTab(vm),
              _buildOfflineTab(vm),
              _buildVaultTab(vm),
            ],
          ),
          bottomNavigationBar: NavigationBar(
            selectedIndex: _tabIndex,
            onDestinationSelected: (i) => setState(() => _tabIndex = i),
            destinations: [
              const NavigationDestination(
                icon: Icon(Icons.camera_alt_outlined),
                selectedIcon: Icon(Icons.camera_alt_rounded),
                label: 'Snap & ERP',
              ),
              NavigationDestination(
                icon: Badge(
                  isLabelVisible: vm.offlineQueue.isNotEmpty,
                  label: Text('${vm.offlineQueue.length}'),
                  child: const Icon(Icons.cloud_off_outlined),
                ),
                label: 'Offline',
              ),
              const NavigationDestination(
                icon: Icon(Icons.folder_outlined),
                selectedIcon: Icon(Icons.folder_rounded),
                label: 'My Vault',
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildSnapTab(HomeViewModel vm) {
    final erpRes = vm.erpResult;
    final cap = vm.captureResult;
    final boot = vm.bootstrap;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (vm.error != null)
          Card(
            color: const Color(0xFFFEF2F2),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Text(vm.error!, style: const TextStyle(color: Color(0xFFB91C1C), fontSize: 12)),
            ),
          ),
        if (vm.statusMessage != null)
          Card(
            color: const Color(0xFFECFDF5),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Text(vm.statusMessage!, style: const TextStyle(color: Color(0xFF065F46), fontSize: 12)),
            ),
          ),
        if (erpRes != null) ...[
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.check_circle_rounded, color: BetterDocTheme.emeraldAccent, size: 28),
                      SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'Invoice Posted to ERP & Archived',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Text(erpRes.erpMessage, style: const TextStyle(fontSize: 13)),
                  const Divider(height: 24),
                  _kvRow('Vendor', erpRes.vendorName),
                  _kvRow('Invoice #', erpRes.invoiceNumber),
                  _kvRow('Total', '${erpRes.currency} ${(erpRes.totalCents / 100).toStringAsFixed(2)}'),
                  if (erpRes.whtApplicable)
                    _kvRow(
                      '30% ZIMRA WHT',
                      '${erpRes.currency} ${(erpRes.whtCents / 100).toStringAsFixed(2)} (Net: ${(erpRes.netPayableCents / 100).toStringAsFixed(2)})',
                    ),
                  _kvRow('ERP Target', '${erpRes.erpTargetName} (${erpRes.erpSyncStatus})'),
                  _kvRow('Vault Status', erpRes.document.status.toUpperCase()),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: () {
                      setState(() {
                        _pickedBytes = null;
                        _hydratedDocId = null;
                        _titleCtrl.clear();
                      });
                      vm.clearCaptureFlow();
                    },
                    icon: const Icon(Icons.camera_alt_rounded),
                    label: const Text('Snap Next Document'),
                  ),
                ],
              ),
            ),
          ),
        ] else if (cap != null) ...[
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Icon(
                        cap.isInvoice ? Icons.receipt_long_rounded : Icons.description_rounded,
                        color: BetterDocTheme.emeraldAccent,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          cap.isInvoice
                              ? 'AI Detected Invoice (${(cap.confidence * 100).round()}%)'
                              : 'Classified: ${cap.detectedDocType.toUpperCase()}',
                          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                        ),
                      ),
                      TextButton(
                        onPressed: () {
                          setState(() => _hydratedDocId = null);
                          vm.clearCaptureFlow();
                        },
                        child: const Text('New Snap'),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  if (cap.isInvoice) ...[
                    TextField(controller: _vendorNameCtrl, decoration: const InputDecoration(labelText: 'Supplier Name')),
                    const SizedBox(height: 10),
                    TextField(controller: _invoiceNoCtrl, decoration: const InputDecoration(labelText: 'Invoice Number')),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _invoiceDateCtrl,
                            decoration: const InputDecoration(labelText: 'Invoice Date (YYYY-MM-DD)'),
                          ),
                        ),
                        const SizedBox(width: 10),
                        SizedBox(
                          width: 110,
                          child: DropdownButtonFormField<String>(
                            initialValue: _currency,
                            decoration: const InputDecoration(labelText: 'Currency'),
                            items: const ['USD', 'ZWG', 'ZAR', 'EUR', 'GBP']
                                .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                                .toList(),
                            onChanged: (v) => setState(() => _currency = v ?? 'USD'),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _subtotalCtrl,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            decoration: const InputDecoration(labelText: 'Subtotal'),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: TextField(
                            controller: _vatCtrl,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            decoration: const InputDecoration(labelText: 'VAT'),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: TextField(
                            controller: _totalCtrl,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            decoration: const InputDecoration(labelText: 'Total'),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String>(
                      initialValue: _erpSelection,
                      decoration: const InputDecoration(labelText: 'Accounting ERP Target'),
                      items: [
                        ...cap.erpTargets.map(
                          (t) => DropdownMenuItem(
                            value: t.selectionKey,
                            child: Text('${t.mode == 'lan_bridge' ? 'LAN Bridge: ' : 'Cloud: '}${t.name}'),
                          ),
                        ),
                        const DropdownMenuItem(
                          value: 'none',
                          child: Text('BetterDoc AP Finance Ledger Only'),
                        ),
                      ],
                      onChanged: (v) => setState(() => _erpSelection = v ?? 'none'),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      title: const Text('Auto-Approve & Archive After ERP Push', style: TextStyle(fontSize: 13)),
                      value: _archiveDoc,
                      onChanged: (v) => setState(() => _archiveDoc = v),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      title: const Text('Apply 7-Year WORM Compliance Lock', style: TextStyle(fontSize: 13)),
                      value: _wormLock,
                      onChanged: (v) => setState(() => _wormLock = v),
                    ),
                    const SizedBox(height: 10),
                    FilledButton.icon(
                      onPressed: vm.isBusy ? null : _submitInvoiceToErp,
                      style: FilledButton.styleFrom(backgroundColor: BetterDocTheme.emeraldAccent),
                      icon: const Icon(Icons.send_rounded),
                      label: Text(vm.isBusy ? 'Syncing to ERP...' : 'Post to Accounting ERP & Archive'),
                    ),
                  ] else ...[
                    Text('Vault Ref: ${cap.document.docNumber} · ${cap.document.title}'),
                    const SizedBox(height: 12),
                    FilledButton.icon(
                      onPressed: vm.isBusy ? null : () => vm.archiveDocument(cap.document.id),
                      icon: const Icon(Icons.archive_outlined),
                      label: const Text('Archive in Vault'),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ] else ...[
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_pickedBytes != null) ...[
                    ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: Image.memory(_pickedBytes!, height: 220, fit: BoxFit.contain),
                    ),
                    const SizedBox(height: 12),
                  ],
                  Row(
                    children: [
                      Expanded(
                        child: FilledButton.icon(
                          onPressed: () => _pickImage(ImageSource.camera),
                          icon: const Icon(Icons.camera_alt_rounded),
                          label: const Text('Take Photo'),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () => _pickImage(ImageSource.gallery),
                          icon: const Icon(Icons.photo_library_outlined),
                          label: const Text('Gallery'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  TextField(
                    controller: _titleCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Document Title (Optional)',
                      hintText: 'e.g. Fuel Receipt, Tax Invoice, Delivery Note',
                    ),
                  ),
                  const SizedBox(height: 12),
                  if (boot != null && boot.cabinets.isNotEmpty)
                    DropdownButtonFormField<String>(
                      initialValue: _selectedCabinetId,
                      decoration: const InputDecoration(labelText: 'Target Cabinet'),
                      items: boot.cabinets
                          .map((c) => DropdownMenuItem(value: c.id, child: Text(c.name)))
                          .toList(),
                      onChanged: (v) => setState(() => _selectedCabinetId = v),
                    ),
                  const SizedBox(height: 12),
                  if (boot != null && boot.clearances.isNotEmpty)
                    DropdownButtonFormField<String>(
                      initialValue: _selectedClearanceId,
                      decoration: const InputDecoration(labelText: 'Security Classification'),
                      items: boot.clearances
                          .map((c) => DropdownMenuItem(value: c.id, child: Text(c.name)))
                          .toList(),
                      onChanged: (v) => setState(() => _selectedClearanceId = v),
                    ),
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Treat as Accounting Invoice (ERP Routing)', style: TextStyle(fontSize: 13)),
                    value: _forceInvoice,
                    onChanged: (v) => setState(() => _forceInvoice = v),
                  ),
                  const SizedBox(height: 10),
                  FilledButton.icon(
                    onPressed: (_pickedBytes == null || vm.isBusy) ? null : _submitCapture,
                    icon: vm.isBusy
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Icon(Icons.cloud_upload_rounded),
                    label: Text(vm.isBusy ? 'Scanning & Running AI...' : 'Upload, Scan & Detect with AI'),
                  ),
                ],
              ),
            ),
          ),
        ],
      ],
    );
  }

  Widget _buildOfflineTab(HomeViewModel vm) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              'Offline Queue (${vm.offlineQueue.length})',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
            ),
            FilledButton.icon(
              onPressed: (vm.offlineQueue.isEmpty || vm.isBusy) ? null : vm.syncOfflineQueue,
              icon: const Icon(Icons.sync_rounded),
              label: const Text('Sync All'),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (vm.offlineQueue.isEmpty)
          const Card(
            child: Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: Text('No offline captures pending. Everything is synced!')),
            ),
          )
        else
          ...vm.offlineQueue.map(
            (item) => Card(
              child: ListTile(
                leading: const Icon(Icons.image_outlined, color: BetterDocTheme.skyPrimary),
                title: Text(item.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text('${item.filename} · ${item.queuedAtIso}'),
                trailing: IconButton(
                  onPressed: () => vm.removeOfflineItem(item.id),
                  icon: const Icon(Icons.delete_outline, color: Colors.redAccent),
                ),
              ),
            ),
          ),
      ],
    );
  }

  Widget _buildVaultTab(HomeViewModel vm) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: TextField(
            controller: _searchCtrl,
            decoration: InputDecoration(
              hintText: 'Search vault, invoices, policy/claim #...',
              prefixIcon: const Icon(Icons.search_rounded),
              suffixIcon: IconButton(
                onPressed: () => vm.refreshWorkspace(query: _searchCtrl.text),
                icon: const Icon(Icons.arrow_forward_rounded),
              ),
            ),
            onSubmitted: (q) => vm.refreshWorkspace(query: q),
          ),
        ),
        Expanded(
          child: vm.documents.isEmpty
              ? const Center(child: Text('No documents found in your clearance scope.'))
              : ListView.builder(
                  padding: const EdgeInsets.all(16),
                  itemCount: vm.documents.length,
                  itemBuilder: (context, index) {
                    final doc = vm.documents[index];
                    return Card(
                      margin: const EdgeInsets.only(bottom: 10),
                      child: ListTile(
                        title: Text(doc.title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('${doc.docNumber} · ${doc.cabinetName} · ${doc.status.toUpperCase()}'),
                            if (doc.erpSummary != null)
                              Text(
                                'ERP: ${doc.erpSummary}',
                                style: const TextStyle(color: BetterDocTheme.emeraldAccent, fontWeight: FontWeight.w600),
                              ),
                          ],
                        ),
                        trailing: doc.status != 'archived'
                            ? IconButton(
                                onPressed: () => vm.archiveDocument(doc.id),
                                icon: const Icon(Icons.archive_outlined),
                                tooltip: 'Archive',
                              )
                            : const Icon(Icons.verified_rounded, color: BetterDocTheme.emeraldAccent),
                      ),
                    );
                  },
                ),
        ),
      ],
    );
  }

  Widget _kvRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(color: Colors.black54, fontSize: 12)),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.end,
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12),
            ),
          ),
        ],
      ),
    );
  }
}
