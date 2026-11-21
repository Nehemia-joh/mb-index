import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:maisha_bora/main.dart';
import 'package:maisha_bora/src/api/api_client.dart';
import 'package:maisha_bora/src/api/models.dart';
import 'package:maisha_bora/src/state/session.dart';

void main() {
  group('API models', () {
    test('ApiUser parses the auth/me payload', () {
      final user = ApiUser.fromJson({
        'id': 7,
        'username': 'jmosha',
        'role': 'therapist',
        'full_name': 'Jane Mosha',
        'email': 'j@example.org',
        'roles': ['therapist', 'admin'],
        'home_route': 'admin',
      });
      expect(user.id, 7);
      expect(user.username, 'jmosha');
      expect(user.roles, containsAll(['therapist', 'admin']));
      expect(user.homeRoute, 'admin');
    });

    test('FormMeta parses subscales and required fields', () {
      final meta = FormMeta.fromJson({
        'subscales': [
          {
            'id': 'rb',
            'name': 'Restricted/Repetitive Behaviors',
            'short': 'RB',
            'item_count': 13,
            'questions': ['Q one', 'Q two'],
          },
        ],
        'gender_options': ['Female', 'Male'],
        'required_fields': ['individual_name', 'school'],
        'required_labels': {
          'individual_name': "Individual's Name",
          'school': 'School',
        },
      });
      expect(meta.subscales, hasLength(1));
      expect(meta.subscales.first.itemCount, 13);
      expect(meta.subscales.first.questions, hasLength(2));
      expect(meta.genderOptions, ['Female', 'Male']);
      expect(meta.requiredLabels['individual_name'], "Individual's Name");
    });

    test('Report parses the POST /assessments report', () {
      final report = Report.fromJson({
        'subscale_results': [
          {
            'id': 'rb',
            'name': 'Restricted/Repetitive Behaviors',
            'short': 'RB',
            'raw_score': 26,
            'scaled_score': 11,
            'percentile_rank': '63',
            'item_scores': [2, 2],
          },
        ],
        'use_six': true,
        'sum_scaled': 65,
        'composite_percentile': '70',
        'autism_index': 108,
        'probability': 'Very Likely',
        'severity': 'Level 3',
        'descriptor': 'Requiring Very Substantial Support',
      });
      expect(report.autismIndex, 108);
      expect(report.sumScaled, 65);
      expect(report.severity, 'Level 3');
      expect(report.subscaleResults.first.scaledScore, 11);
      expect(report.useSix, isTrue);
    });

    test('AssessmentDetail reads nested score maps', () {
      final detail = AssessmentDetail.fromJson({
        'id': 42,
        'individual_name': 'Asha',
        'school': 'Mwenge',
        'rater_name': 'R',
        'rater_title': 'Teacher',
        'age': '7',
        'gender': 'Female',
        'examiner_name': 'E',
        'examiner_title': 'Psych',
        'is_mute': false,
        'scores': {
          'raw': {'RB': 26, 'SI': 30},
          'scaled': {'RB': 11, 'SI': 12},
          'percentiles': {'RB': 63, 'SI': 75},
          'sum_scaled': 65,
          'composite_percentile': '70',
          'autism_index': 108,
          'probability': 'Very Likely',
          'severity_level': 'Level 3',
          'severity_desc': 'Requiring Very Substantial Support',
        },
        'created_at': '2026-11-21 10:00:00',
      });
      expect(detail.id, 42);
      expect(detail.autismIndex, 108);
      expect(detail.scaled['RB'], 11);
      expect(detail.percentiles['SI'], 75);
      expect(detail.severityLevel, 'Level 3');
      expect(detail.isMute, isFalse);
    });

    test('ApiException flags dead-session codes', () {
      expect(ApiException(401, 'token_revoked', 'x').isAuth, isTrue);
      expect(ApiException(401, 'account_disabled', 'x').isAuth, isTrue);
      expect(ApiException(401, 'invalid_credentials', 'x').isAuth, isFalse);
      expect(ApiException(422, 'validation', 'x').isAuth, isFalse);
      expect(ApiException(423, 'locked', 'x').isAuth, isFalse);
    });
  });

  testWidgets('app opens on the sign-in screen', (tester) async {
    // fake storage: no plugin channel exists under testWidgets
    final session = Session(
      client: ApiClient(baseUrl: ApiClient.defaultBaseUrl),
      store: _FakeTokenStore(),
    );
    await tester.pumpWidget(MaishaBoraApp(session: session));
    await tester.pump(); // restore() resolves as signed-out
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Maisha Bora'), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.byType(TextField), findsWidgets);
  });

  testWidgets('sign-in screen offers the previously used server address',
      (tester) async {
    const savedUrl = 'http://192.168.0.168:8123/api/v1';
    final session = Session(
      client: ApiClient(baseUrl: ApiClient.defaultBaseUrl),
      store: _FakeTokenStore(url: savedUrl), // stored, but signed out
    );
    await tester.pumpWidget(MaishaBoraApp(session: session));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    // the emulator default must not overwrite what the user already typed
    expect(find.text(savedUrl), findsWidgets);
    expect(find.text(ApiClient.defaultBaseUrl), findsNothing);
  });
}

class _FakeTokenStore implements TokenStore {
  _FakeTokenStore({this.url});

  final String? url;

  @override
  Future<String?> readBaseUrl() async => url;

  @override
  Future<String?> readToken() async => null;

  @override
  Future<void> saveSession(
          {required String token, required String baseUrl}) async {}

  @override
  Future<void> saveBaseUrl(String baseUrl) async {}

  @override
  Future<void> clear() async {}
}
