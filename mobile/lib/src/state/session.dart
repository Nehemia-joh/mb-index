import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api/api_client.dart';
import '../api/models.dart';

/// Persists the bearer token in the Android Keystore.
///
/// The server address is not user-configurable: the app always talks to the
/// production endpoint ([ApiClient.defaultBaseUrl]).
class TokenStore {
  TokenStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  final FlutterSecureStorage _storage;

  static const _kToken = 'gars_token';
  static const _kLegacyUrl = 'gars_base_url'; // written by older builds

  Future<void> saveSession({required String token}) =>
      _storage.write(key: _kToken, value: token);

  Future<String?> readToken() => _storage.read(key: _kToken);

  Future<void> clear() async {
    try {
      await _storage.delete(key: _kToken);
      await _storage.delete(key: _kLegacyUrl);
    } catch (_) {
      // plugin missing in tests — nothing to clear
    }
  }
}

/// App-wide auth state: who is signed in.
class Session extends ChangeNotifier {
  Session({required this.client, required this.store});

  final ApiClient client;
  final TokenStore store;

  ApiUser? user;
  String? error;
  bool busy = false;
  bool restored = false;

  bool get signedIn => user != null;

  /// Restore a previous session from secure storage on app start.
  Future<void> restore() async {
    try {
      final savedToken = await store.readToken();
      if (savedToken == null || savedToken.isEmpty) {
        restored = true;
        notifyListeners();
        return;
      }
      client.token = savedToken;
      user = await client.me();
    } on ApiException catch (e) {
      if (e.isAuth || e.statusCode == 401) {
        await store.clear();
        client.token = null;
      }
      // network errors keep the token: the user may be offline right now
    } catch (_) {
      // storage unavailable (e.g. tests) — treat as signed out
    }
    restored = true;
    notifyListeners();
  }

  Future<bool> login(String username, String password) async {
    busy = true;
    error = null;
    notifyListeners();
    try {
      final result = await client.login(username, password);
      user = result.user;
      await store.saveSession(token: result.token);
      return true;
    } on ApiException catch (e) {
      error = e.message;
      return false;
    } catch (_) {
      error = 'Unexpected error while signing in.';
      return false;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    busy = true;
    notifyListeners();
    try {
      await client.logout();
    } catch (_) {
      // best effort
    }
    await store.clear();
    client.token = null;
    user = null;
    busy = false;
    notifyListeners();
  }

  /// Called by screens when a request fails with an auth error: the token
  /// died server-side (revoked, expired, account disabled) — sign out.
  Future<void> sessionExpired(String message) async {
    error = message;
    await store.clear();
    client.token = null;
    user = null;
    notifyListeners();
  }
}
