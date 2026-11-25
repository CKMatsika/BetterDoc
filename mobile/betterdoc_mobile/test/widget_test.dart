import 'dart:convert';

import 'package:betterdoc_mobile/data/repositories/betterdoc_repository.dart';
import 'package:betterdoc_mobile/data/services/betterdoc_api_service.dart';
import 'package:betterdoc_mobile/domain/models/betterdoc_models.dart';
import 'package:betterdoc_mobile/main.dart';
import 'package:betterdoc_mobile/ui/features/home/view_models/home_view_model.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  testWidgets('renders BetterDoc Mobile login screen when unauthenticated', (tester) async {
    final mockClient = MockClient((req) async => http.Response('{}', 200));
    final vm = HomeViewModel(
      repository: BetterDocRepository(apiService: BetterDocApiService(client: mockClient)),
    );

    await tester.pumpWidget(BetterDocMobileApp(viewModel: vm));
    await tester.pumpAndSettle();

    expect(find.text('BetterDoc Mobile'), findsOneWidget);
    expect(find.text('Sign In to Vault'), findsOneWidget);
  });

  test('BetterDocApiService parses CaptureResult & ErpProcessResult with ZIMRA WHT accurately', () {
    final captureJson = jsonDecode('''
      {
        "document": {
          "id": "doc-1",
          "doc_number": "SCN/20261125/001",
          "title": "Supplier Tax Invoice",
          "status": "active",
          "is_worm": false,
          "cabinet_name": "Finance",
          "classification_name": "Internal"
        },
        "extraction": {
          "detected_doc_type": "invoice",
          "overall_confidence": 0.94
        },
        "is_invoice": true,
        "suggested_invoice": {
          "vendor_name": "Harare Industrial Supplies",
          "invoice_number": "INV-2026-001",
          "invoice_date": "2026-11-25",
          "currency": "USD",
          "subtotal_cents": 40000,
          "vat_cents": 6000,
          "total_cents": 46000,
          "description": "Mobile Captured Invoice"
        },
        "erp_targets": [
          {
            "target_type": "bridge",
            "target_id": "agent-1",
            "name": "Harare Pastel Bridge",
            "system_type": "sage_pastel_evolution",
            "mode": "lan_bridge",
            "status": "online"
          }
        ]
      }
    ''') as Map<String, Object?>;

    final capture = CaptureResult.fromJson(captureJson);
    expect(capture.isInvoice, isTrue);
    expect(capture.suggestedInvoice?.totalCents, 46000);
    expect(capture.erpTargets.first.selectionKey, 'bridge:agent-1');

    final erpJson = jsonDecode('''
      {
        "document": {
          "id": "doc-1",
          "doc_number": "SCN/20261125/001",
          "title": "Supplier Tax Invoice",
          "status": "archived",
          "is_worm": true,
          "cabinet_name": "Finance",
          "classification_name": "Internal"
        },
        "ap_invoice": {
          "invoice_number": "INV-2026-001",
          "vendor_name": "Harare Industrial Supplies",
          "currency": "USD",
          "total_cents": 46000,
          "wht_applicable": true,
          "wht_cents": 12000,
          "net_payable_cents": 34000
        },
        "erp_result": {
          "target_name": "Harare Pastel Bridge",
          "status": "queued_for_lan_bridge",
          "message": "Queued for Windows LAN Bridge"
        }
      }
    ''') as Map<String, Object?>;

    final erp = ErpProcessResult.fromJson(erpJson);
    expect(erp.whtApplicable, isTrue);
    expect(erp.whtCents, 12000);
    expect(erp.netPayableCents, 34000);
    expect(erp.document.status, 'archived');
  });
}
