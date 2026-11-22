import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'src/api/api_client.dart';
import 'src/screens/home_screen.dart';
import 'src/screens/login_screen.dart';
import 'src/state/session.dart';
import 'src/theme/app_theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const MaishaBoraApp());
}

class MaishaBoraApp extends StatefulWidget {
  const MaishaBoraApp({super.key, this.session});

  /// Tests may inject a session; production creates its own (with secure
  /// storage and the production server address) exactly once.
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
    return ChangeNotifierProvider<Session>.value(
      value: _session,
      child: MaterialApp(
        title: 'Maisha Bora',
        debugShowCheckedModeBanner: false,
        theme: buildAppTheme(),
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
    return Scaffold(
      backgroundColor: Brand.paper,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Image.asset('assets/icon/mb-mark.png', width: 72, height: 72),
            const SizedBox(height: 16),
            const Text(
              'Maisha Bora',
              style: TextStyle(
                fontFamily: Brand.display,
                fontWeight: FontWeight.w600,
                fontSize: 26,
                color: Brand.brand,
              ),
            ),
            const SizedBox(height: 14),
            const SizedBox(
              width: 26,
              height: 26,
              child: CircularProgressIndicator(
                strokeWidth: 2.5,
                color: Brand.brand,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
