import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'src/api/api_client.dart';
import 'src/screens/home_screen.dart';
import 'src/screens/login_screen.dart';
import 'src/state/session.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const MaishaBoraApp());
}

class MaishaBoraApp extends StatefulWidget {
  const MaishaBoraApp({super.key, this.session});

  /// Tests may inject a session; production creates its own (with secure
  /// storage and the default server address) exactly once.
  final Session? session;

  @override
  State<MaishaBoraApp> createState() => _MaishaBoraAppState();
}

class _MaishaBoraAppState extends State<MaishaBoraApp> {
  late final Session _session;

  @override
  void initState() {
    super.initState();
    _session = widget.session ??
        Session(
          client: ApiClient(baseUrl: ApiClient.defaultBaseUrl),
          store: TokenStore(),
        );
    _session.restore(); // safe offline / without secure storage
  }

  @override
  Widget build(BuildContext context) {
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF0B5CAF)),
    );
    return ChangeNotifierProvider<Session>.value(
      value: _session,
      child: MaterialApp(
        title: 'Maisha Bora GARS-3',
        debugShowCheckedModeBanner: false,
        theme: base.copyWith(
          inputDecorationTheme: const InputDecorationTheme(
            border: OutlineInputBorder(),
            isDense: true,
          ),
          elevatedButtonTheme: ElevatedButtonThemeData(
            style: ElevatedButton.styleFrom(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
            ),
          ),
        ),
        home: Consumer<Session>(
          builder: (context, session, _) {
            if (!session.restored) {
              return const _Splash();
            }
            if (!session.signedIn) {
              return const LoginScreen();
            }
            return const HomeScreen();
          },
        ),
      ),
    );
  }
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 16),
            Text('Maisha Bora GARS-3'),
          ],
        ),
      ),
    );
  }
}
