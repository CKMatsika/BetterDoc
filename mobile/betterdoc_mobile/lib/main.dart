import 'package:flutter/material.dart';

import 'data/repositories/betterdoc_repository.dart';
import 'data/services/betterdoc_api_service.dart';
import 'ui/core/betterdoc_theme.dart';
import 'ui/features/auth/views/login_view.dart';
import 'ui/features/home/view_models/home_view_model.dart';
import 'ui/features/home/views/home_shell_view.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  final api = BetterDocApiService();
  final repo = BetterDocRepository(apiService: api);
  final viewModel = HomeViewModel(repository: repo)..initialize();

  runApp(BetterDocMobileApp(viewModel: viewModel));
}

class BetterDocMobileApp extends StatelessWidget {
  const BetterDocMobileApp({super.key, required this.viewModel});

  final HomeViewModel viewModel;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'BetterDoc Mobile',
      debugShowCheckedModeBanner: false,
      theme: BetterDocTheme.light(),
      home: ListenableBuilder(
        listenable: viewModel,
        builder: (context, _) {
          if (viewModel.session == null) {
            return LoginView(
              onSignIn: viewModel.signIn,
              isLoading: viewModel.isBusy,
              errorMessage: viewModel.error,
            );
          }
          return HomeShellView(viewModel: viewModel);
        },
      ),
    );
  }
}
