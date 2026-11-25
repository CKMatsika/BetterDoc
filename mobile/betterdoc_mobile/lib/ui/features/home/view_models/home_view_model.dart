import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';

import '../../../../data/repositories/betterdoc_repository.dart';
import '../../../../domain/models/betterdoc_models.dart';

class HomeViewModel extends ChangeNotifier {
  HomeViewModel({required BetterDocRepository repository}) : _repo = repository;

  final BetterDocRepository _repo;

  AuthSession? _session;
  AuthSession? get session => _session;

  BootstrapData? _bootstrap;
  BootstrapData? get bootstrap => _bootstrap;

  List<MobileDocumentItem> _documents = const [];
  List<MobileDocumentItem> get documents => _documents;

  List<OfflineQueuedCapture> _offlineQueue = const [];
  List<OfflineQueuedCapture> get offlineQueue => _offlineQueue;

  CaptureResult? _captureResult;
  CaptureResult? get captureResult => _captureResult;

  ErpProcessResult? _erpResult;
  ErpProcessResult? get erpResult => _erpResult;

  bool _isBusy = false;
  bool get isBusy => _isBusy;

  String? _error;
  String? get error => _error;

  String? _statusMessage;
  String? get statusMessage => _statusMessage;

  Future<void> initialize() async {
    _isBusy = true;
    notifyListeners();
    try {
      _session = await _repo.loadSession();
      _offlineQueue = await _repo.listOfflineQueue();
      if (_session != null) {
        await refreshWorkspace();
      }
    } finally {
      _isBusy = false;
      notifyListeners();
    }
  }

  Future<void> signIn(String baseUrl, String email, String password) async {
    _isBusy = true;
    _error = null;
    notifyListeners();
    try {
      _session = await _repo.signIn(baseUrl: baseUrl, email: email, password: password);
      await refreshWorkspace();
    } catch (e) {
      _error = e.toString();
    } finally {
      _isBusy = false;
      notifyListeners();
    }
  }

  Future<void> signOut() async {
    final current = _session;
    if (current != null) {
      await _repo.signOut(current);
    }
    _session = null;
    _bootstrap = null;
    _documents = const [];
    _captureResult = null;
    _erpResult = null;
    notifyListeners();
  }

  Future<void> refreshWorkspace({String query = ''}) async {
    final current = _session;
    if (current == null) {
      return;
    }
    _error = null;
    try {
      _bootstrap = await _repo.getBootstrap(current);
      _documents = await _repo.getDocuments(current, query: query);
      _offlineQueue = await _repo.listOfflineQueue();
    } catch (e) {
      _error = e.toString();
    }
    notifyListeners();
  }

  Future<void> submitCapture({
    required Uint8List bytes,
    required String filename,
    required String cabinetId,
    required String clearanceLevelId,
    required String title,
    bool forceInvoice = false,
  }) async {
    final current = _session;
    if (current == null) {
      return;
    }
    _isBusy = true;
    _error = null;
    _statusMessage = null;
    _erpResult = null;
    notifyListeners();

    try {
      final result = await _repo.captureDocument(
        session: current,
        bytes: bytes,
        filename: filename,
        cabinetId: cabinetId,
        clearanceLevelId: clearanceLevelId,
        title: title,
        forceInvoice: forceInvoice,
      );
      _captureResult = result;
      _documents = [
        result.document,
        ..._documents.where((d) => d.id != result.document.id),
      ];
    } catch (e) {
      // Automatically offer offline queueing on network failure for native mobile devices
      if (!kIsWeb) {
        await _saveToOfflineQueue(
          bytes: bytes,
          filename: filename,
          title: title,
          cabinetId: cabinetId,
          clearanceLevelId: clearanceLevelId,
          forceInvoice: forceInvoice,
        );
        _statusMessage = 'No connection — photo saved to Offline Queue for automatic sync.';
      } else {
        _error = e.toString();
      }
    } finally {
      _isBusy = false;
      notifyListeners();
    }
  }

  Future<void> processInvoiceToErp(Map<String, Object?> payload) async {
    final current = _session;
    final capture = _captureResult;
    if (current == null || capture == null) {
      return;
    }

    _isBusy = true;
    _error = null;
    notifyListeners();

    try {
      final res = await _repo.processInvoiceToErp(
        session: current,
        documentId: capture.document.id,
        payload: payload,
      );
      _erpResult = res;
      _documents = [
        res.document,
        ..._documents.where((d) => d.id != res.document.id),
      ];
    } catch (e) {
      _error = e.toString();
    } finally {
      _isBusy = false;
      notifyListeners();
    }
  }

  Future<void> archiveDocument(String documentId) async {
    final current = _session;
    if (current == null) {
      return;
    }
    _isBusy = true;
    _error = null;
    notifyListeners();
    try {
      final updated = await _repo.archiveDocument(current, documentId);
      _documents = _documents.map((d) => d.id == updated.id ? updated : d).toList();
      _statusMessage = 'Archived ${updated.docNumber} in vault.';
    } catch (e) {
      _error = e.toString();
    } finally {
      _isBusy = false;
      notifyListeners();
    }
  }

  Future<void> syncOfflineQueue() async {
    final current = _session;
    if (current == null || kIsWeb) {
      return;
    }
    _isBusy = true;
    _error = null;
    int synced = 0;
    notifyListeners();

    try {
      final items = await _repo.listOfflineQueue();
      for (final item in items) {
        final file = File(item.filePath);
        if (!await file.exists()) {
          await _repo.removeOffline(item.id);
          continue;
        }
        final bytes = await file.readAsBytes();
        final res = await _repo.captureDocument(
          session: current,
          bytes: bytes,
          filename: item.filename,
          cabinetId: item.cabinetId,
          clearanceLevelId: item.clearanceLevelId,
          title: item.title,
          forceInvoice: item.forceInvoice,
        );
        await _repo.removeOffline(item.id);
        await file.delete();
        synced++;
        if (res.isInvoice) {
          _captureResult = res;
        }
      }
      _offlineQueue = await _repo.listOfflineQueue();
      _statusMessage = 'Synced $synced offline capture(s) into BetterDoc AI & Vault.';
      await refreshWorkspace();
    } catch (e) {
      _error = e.toString();
    } finally {
      _isBusy = false;
      notifyListeners();
    }
  }

  Future<void> removeOfflineItem(String id) async {
    await _repo.removeOffline(id);
    _offlineQueue = await _repo.listOfflineQueue();
    notifyListeners();
  }

  void clearCaptureFlow() {
    _captureResult = null;
    _erpResult = null;
    _error = null;
    _statusMessage = null;
    notifyListeners();
  }

  Future<void> _saveToOfflineQueue({
    required Uint8List bytes,
    required String filename,
    required String title,
    required String cabinetId,
    required String clearanceLevelId,
    required bool forceInvoice,
  }) async {
    final dir = await getApplicationDocumentsDirectory();
    final id = DateTime.now().millisecondsSinceEpoch.toString();
    final path = '${dir.path}${Platform.pathSeparator}bd_offline_${id}_$filename';
    await File(path).writeAsBytes(bytes, flush: true);

    await _repo.enqueueOffline(
      OfflineQueuedCapture(
        id: id,
        filePath: path,
        filename: filename,
        title: title.isNotEmpty ? title : 'Offline Field Capture',
        cabinetId: cabinetId,
        clearanceLevelId: clearanceLevelId,
        forceInvoice: forceInvoice,
        queuedAtIso: DateTime.now().toIso8601String(),
      ),
    );
    _offlineQueue = await _repo.listOfflineQueue();
  }
}
