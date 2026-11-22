import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

import 'package:maisha_bora/main.dart';
import 'package:maisha_bora/src/api/api_client.dart';
import 'package:maisha_bora/src/api/models.dart';
import 'package:maisha_bora/src/screens/report_screen.dart';
import 'package:maisha_bora/src/state/session.dart';
import 'package:maisha_bora/src/util/age.dart';

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

    test('AssessmentDetail reads stored item marks for editing', () {
      final detail = AssessmentDetail.fromJson({
        'id': 7,
        'individual_name': 'Joan',
        'scores': {
          'raw': {'RB': 3},
          'autism_index': 78,
        },
        // same single-key-per-subscale shape edit.php rebuilds from
        'item_scores': [
          {'RB': [2, 1, 0, 3]},
          {'SI': [1, 1]},
          {'not-a-list': 'ignored'},
        ],
      });
      expect(detail.itemScores['RB'], [2, 1, 0, 3]);
      expect(detail.itemScores['SI'], [1, 1]);
      expect(detail.itemScores.containsKey('not-a-list'), isFalse);
      // absent key stays empty — a legacy row without marks just opens blank
      expect(
          AssessmentDetail.fromJson({'id': 1, 'scores': <String, dynamic>{}})
              .itemScores,
          isEmpty);
    });

    test('ApiException flags dead-session codes', () {
      expect(ApiException(401, 'token_revoked', 'x').isAuth, isTrue);
      expect(ApiException(401, 'account_disabled', 'x').isAuth, isTrue);
      expect(ApiException(401, 'invalid_credentials', 'x').isAuth, isFalse);
      expect(ApiException(422, 'validation', 'x').isAuth, isFalse);
      expect(ApiException(423, 'locked', 'x').isAuth, isFalse);
    });
  });

  group('age auto-calculation', () {
    test('measured at the date of rating when one is set', () {
      expect(ageAt(DateTime(2019, 6, 15), DateTime(2026, 6, 14)), 6);
      expect(ageAt(DateTime(2019, 6, 15), DateTime(2026, 6, 15)), 7);
      expect(ageAt(DateTime(2019, 6, 15), DateTime(2026, 6, 16)), 7);
    });

    test('falls back to today when no rating date is set', () {
      final today = DateTime.now();
      expect(ageAt(DateTime(today.year - 7, today.month, today.day)), 7);
      // birthday tomorrow → still 6
      expect(ageAt(DateTime(today.year - 7, today.month, today.day + 1)), 6);
    });

    test('rejects implausible pairs instead of filling nonsense', () {
      expect(ageAt(DateTime(2030, 1, 1)), isNull); // born in the future
      expect(ageAt(DateTime(1800, 1, 1)), isNull); // older than 120
      expect(ageAt(DateTime(2020, 1, 1), DateTime(2019, 1, 1)), isNull);
    });
  });

  group('server address validation', () {
    test('accepts complete addresses', () {
      expect(ApiClient.looksLikeServerUrl('http://192.168.0.168:8123/api/v1'),
          isTrue);
      expect(ApiClient.looksLikeServerUrl('http://10.0.2.2:8123/api/v1'), isTrue);
      expect(ApiClient.looksLikeServerUrl('https://gars.example.org/api/v1'),
          isTrue);
      expect(ApiClient.looksLikeServerUrl('http://localhost:8123/api/v1'),
          isTrue);
    });

    test('rejects the truncated-IP typo seen on device', () {
      // what was actually typed during field testing: 192.168.:8123
      expect(ApiClient.looksLikeServerUrl('http://192.168.:8123/api/v1'),
          isFalse);
      expect(ApiClient.looksLikeServerUrl('http://192.168.0.999:8123/api/v1'),
          isFalse);
      expect(ApiClient.looksLikeServerUrl('192.168.0.168:8123'), isFalse);
      expect(ApiClient.looksLikeServerUrl(''), isFalse);
      expect(ApiClient.looksLikeServerUrl('not a url'), isFalse);
    });

    test('a rejected address fails before any request is sent', () async {
      final client = ApiClient(baseUrl: 'http://192.168.:8123/api/v1');
      try {
        await client.metaForm();
        fail('expected an ApiException');
      } on ApiException catch (e) {
        expect(e.code, 'bad_url');
        expect(e.statusCode, 0);
      } finally {
        client.close();
      }
    });
  });

  group('stale-connection retry (GET only)', () {
    test('a GET is retried once when the pooled socket is dead', () async {
      var calls = 0;
      final client = ApiClient(
        baseUrl: 'https://mb-index.nexabie.co.tz/api/v1',
        httpClient: MockClient((request) async {
          calls++;
          if (calls == 1) throw const SocketException('stale keep-alive socket');
          return http.Response(
            jsonEncode({
              'ok': true,
              'data': {
                'items': <dynamic>[],
                'total': 0,
                'has_more': false,
                'scope': 'own',
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      );
      addTearDown(client.close);

      final result = await client.listAssessments();
      expect(result.items, isEmpty);
      expect(calls, 2, reason: 'first attempt hit the dead socket, retry worked');
    });

    test('a POST is never retried — it may have reached the server', () async {
      var calls = 0;
      final client = ApiClient(
        baseUrl: 'https://mb-index.nexabie.co.tz/api/v1',
        httpClient: MockClient((request) async {
          calls++;
          throw const SocketException('connection reset');
        }),
      );
      addTearDown(client.close);

      await expectLater(
        client.login('someone', 'secret'),
        throwsA(isA<ApiException>()
            .having((e) => e.code, 'code', 'network')
            .having((e) => e.statusCode, 'statusCode', 0)),
      );
      expect(calls, 1, reason: 'a save/login must not be replayed silently');
    });
    test('form meta is fetched once and then served from the session cache',
        () async {
      var calls = 0;
      final client = ApiClient(
        baseUrl: 'https://mb-index.nexabie.co.tz/api/v1',
        httpClient: MockClient((request) async {
          calls++;
          return http.Response(
            jsonEncode({
              'ok': true,
              'data': {
                'gender_options': ['Male'],
                'required_fields': ['individual_name'],
                'required_labels': {'individual_name': "Individual's name"},
                'subscales': [
                  {
                    'id': 'rb',
                    'short': 'RB',
                    'name': 'Role Behaviour',
                    'item_count': 2,
                  },
                ],
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      );
      addTearDown(client.close);

      final first = await client.metaForm();
      final second = await client.metaForm();
      expect(calls, 1, reason: 'the second call must hit the cache');
      expect(second.subscales, first.subscales);
    });
  });

  group('record editing', () {
    test('updateAssessment PUTs the full form and parses the response',
        () async {
      var calls = 0;
      String? method;
      String? path;
      Map<String, dynamic>? sent;
      final client = ApiClient(
        baseUrl: 'https://mb-index.nexabie.co.tz/api/v1',
        httpClient: MockClient((request) async {
          calls++;
          method = request.method;
          path = request.url.path;
          sent = jsonDecode(request.body) as Map<String, dynamic>;
          return http.Response(
            jsonEncode({
              'ok': true,
              'data': {
                'id': 7,
                'record': {
                  'id': 7,
                  'individual_name': sent!['individual_name'],
                  'scores': {'autism_index': 91},
                },
                'report': {
                  'autism_index': 91,
                  'sum_scaled': 70,
                  'subscale_results': <dynamic>[],
                },
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      );
      addTearDown(client.close);

      final result = await client
          .updateAssessment(7, {'individual_name': 'Joan', 'rb_item_0': 3});
      expect(calls, 1, reason: 'a PUT is never silently replayed');
      expect(method, 'PUT');
      expect(path, '/api/v1/assessments/7');
      expect(sent!['rb_item_0'], 3, reason: 'the full form travels');
      expect(result.report.autismIndex, 91);
      expect(result.record.id, 7);
      expect(result.record.individualName, 'Joan');
    });

    test('a PUT failure surfaces as an ApiException, not a retry', () async {
      var calls = 0;
      final client = ApiClient(
        baseUrl: 'https://mb-index.nexabie.co.tz/api/v1',
        httpClient: MockClient((request) async {
          calls++;
          throw const SocketException('connection reset');
        }),
      );
      addTearDown(client.close);

      await expectLater(
        client.updateAssessment(7, {'individual_name': 'Joan'}),
        throwsA(isA<ApiException>()
            .having((e) => e.code, 'code', 'network')
            .having((e) => e.statusCode, 'statusCode', 0)),
      );
      expect(calls, 1, reason: 'only GETs are retried on stale sockets');
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

  testWidgets('sign-in screen is production-only (no server settings)',
      (tester) async {
    final session = Session(
      client: ApiClient(baseUrl: ApiClient.defaultBaseUrl),
      store: _FakeTokenStore(),
    );
    await tester.pumpWidget(MaishaBoraApp(session: session));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Server settings'), findsNothing);
    expect(find.textContaining('API base URL'), findsNothing);
    expect(ApiClient.defaultBaseUrl,
        'https://mb-index.nexabie.co.tz/api/v1');
  });

  testWidgets(
      'edit flow: report → Edit → prefilled form → PUT → recalculated report',
      (tester) async {
    var putCalls = 0;
    Map<String, dynamic>? sent;
    final client = ApiClient(
      baseUrl: ApiClient.defaultBaseUrl,
      httpClient: MockClient((request) async {
        if (request.method == 'GET' &&
            request.url.path.endsWith('/meta/form')) {
          return http.Response(
            jsonEncode({
              'ok': true,
              'data': {
                'gender_options': ['Female', 'Male'],
                'required_fields': [
                  'individual_name',
                  'school',
                  'rater_name',
                  'rater_title',
                  'date_of_birth',
                  'age',
                  'gender',
                  'examiner_name',
                  'examiner_title',
                  'date_of_rating',
                ],
                'required_labels': {
                  'individual_name': "Individual's Name",
                  'school': 'School',
                  'rater_name': "Rater's Name",
                  'rater_title': "Rater's Title",
                  'date_of_birth': 'Date of Birth',
                  'age': 'Age',
                  'gender': 'Gender',
                  'examiner_name': "Examiner's Name",
                  'examiner_title': "Examiner's Title",
                  'date_of_rating': 'Date of Rating',
                },
                'subscales': [
                  {
                    'id': 'rb',
                    'short': 'RB',
                    'name': 'Role Behaviour',
                    'item_count': 2,
                    'questions': ['Q one', 'Q two'],
                  },
                  {
                    'id': 'si',
                    'short': 'SI',
                    'name': 'Social Interaction',
                    'item_count': 2,
                    'questions': ['Q three', 'Q four'],
                  },
                ],
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        if (request.method == 'PUT') {
          putCalls++;
          sent = jsonDecode(request.body) as Map<String, dynamic>;
          return http.Response(
            jsonEncode({
              'ok': true,
              'data': {
                'id': 7,
                'record': {
                  'id': 7,
                  'individual_name': sent!['individual_name'],
                  'scores': {'autism_index': 91},
                },
                'report': {
                  'autism_index': 91,
                  'sum_scaled': 70,
                  'subscale_results': <dynamic>[],
                },
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response(
          jsonEncode({
            'ok': false,
            'error': {
              'code': 'unexpected',
              'message': 'unexpected ${request.method} ${request.url.path}',
            },
          }),
          500,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    addTearDown(client.close);
    final session = Session(client: client, store: _FakeTokenStore());

    final detail = AssessmentDetail.fromJson({
      'id': 7,
      'individual_name': 'Joan Mushi',
      'school': 'Mwanza Primary',
      'rater_name': 'Jane Mosha',
      'rater_title': 'Teacher',
      'date_of_birth': '2018-05-04',
      'age': '7',
      'gender': 'Female',
      'examiner_name': 'Dr Mwana',
      'examiner_title': 'Psychologist',
      'date_of_rating': '2026-11-20',
      'scores': {
        'raw': {'RB': 3, 'SI': 2},
        'scaled': {'RB': 5, 'SI': 4},
        'percentiles': {'RB': 40, 'SI': 35},
        'sum_scaled': 60,
        'composite_percentile': '35',
        'autism_index': 78,
        'probability': 'Very Likely',
        'severity_level': 'Level 2',
        'severity_desc': 'Requiring Substantial Support',
      },
      'item_scores': [
        {'RB': [1, 2]},
        {'SI': [0, 3]},
      ],
    });

    await tester.pumpWidget(ChangeNotifierProvider<Session>.value(
      value: session,
      child: MaterialApp(
        home: ReportScreen(detail: detail, report: null, recordId: 7),
      ),
    ));
    await tester.pumpAndSettle();

    // the stored record view offers the edit (below the fold on the short
    // test surface — the ListView builds it lazily)
    expect(find.text('Record #7'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Edit record'), 200);
    await tester.pumpAndSettle();
    expect(find.text('Edit record'), findsOneWidget);

    await tester.tap(find.text('Edit record'));
    await tester.pumpAndSettle();

    // the form opens prefilled from the stored record
    expect(find.text('Edit record #7'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Joan Mushi'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Mwanza Primary'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Jane Mosha'), findsOneWidget);
    expect(find.widgetWithText(TextField, '2018-05-04'), findsOneWidget);

    // save: the full form travels over PUT (fixed pumps — a covered screen
    // still holds the submitting spinner, which pumpAndSettle would await)
    await tester.tap(find.text('Recalculate and update'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    await tester.pump(const Duration(milliseconds: 600));

    expect(putCalls, 1, reason: 'exactly one PUT for one save');
    expect(sent!['individual_name'], 'Joan Mushi');
    expect(sent!['school'], 'Mwanza Primary');
    expect(sent!['gender'], 'Female');
    expect(sent!['date_of_birth'], '2018-05-04');
    expect(sent!['rb_item_0'], 1, reason: 'stored marks prefilled the form');
    expect(sent!['rb_item_1'], 2);
    expect(sent!['si_item_1'], 3);

    // the recalculated report sits on top with the new Autism Index (91)
    expect(find.text('91'), findsOneWidget);
    expect(find.text('Autism Index'), findsWidgets);
  });
}

class _FakeTokenStore implements TokenStore {
  _FakeTokenStore();

  @override
  Future<String?> readToken() async => null;

  @override
  Future<void> saveSession({required String token}) async {}

  @override
  Future<void> clear() async {}
}
