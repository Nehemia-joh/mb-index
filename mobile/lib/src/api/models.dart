/// JSON models for the Maisha Bora /api/v1 contract (see api/index.php).
library;

class ApiException implements Exception {
  ApiException(this.statusCode, this.code, this.message);

  final int statusCode;
  final String code;
  final String message;

  /// The session is gone — token missing, revoked, expired or account off.
  bool get isAuth =>
      statusCode == 401 &&
      const {
        'missing_token',
        'invalid_token',
        'token_revoked',
        'token_expired',
        'account_disabled',
      }.contains(code);

  @override
  String toString() => message;
}

class ApiUser {
  const ApiUser({
    required this.id,
    required this.username,
    required this.role,
    required this.fullName,
    required this.email,
    required this.roles,
    required this.homeRoute,
  });

  final int id;
  final String username;
  final String role;
  final String fullName;
  final String email;
  final List<String> roles;
  final String homeRoute;

  factory ApiUser.fromJson(Map<String, dynamic> json) => ApiUser(
        id: (json['id'] as num?)?.toInt() ?? 0,
        username: json['username'] as String? ?? '',
        role: json['role'] as String? ?? '',
        fullName: json['full_name'] as String? ?? '',
        email: json['email'] as String? ?? '',
        roles: (json['roles'] as List?)?.map((e) => '$e').toList() ?? const [],
        homeRoute: json['home_route'] as String? ?? 'therapist',
      );
}

class Subscale {
  const Subscale({
    required this.id,
    required this.name,
    required this.short,
    required this.itemCount,
    required this.questions,
  });

  final String id;
  final String name;
  final String short;
  final int itemCount;
  final List<String> questions;

  factory Subscale.fromJson(Map<String, dynamic> json) => Subscale(
        id: json['id'] as String? ?? '',
        name: json['name'] as String? ?? '',
        short: json['short'] as String? ?? '',
        itemCount: (json['item_count'] as num?)?.toInt() ?? 0,
        questions:
            (json['questions'] as List?)?.map((e) => '$e').toList() ?? const [],
      );
}

class FormMeta {
  const FormMeta({
    required this.subscales,
    required this.genderOptions,
    required this.requiredFields,
    required this.requiredLabels,
  });

  final List<Subscale> subscales;
  final List<String> genderOptions;
  final List<String> requiredFields;
  final Map<String, String> requiredLabels;

  factory FormMeta.fromJson(Map<String, dynamic> json) => FormMeta(
        subscales: ((json['subscales'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(Subscale.fromJson)
            .toList(),
        genderOptions: ((json['gender_options'] as List?) ?? const [])
            .map((e) => '$e')
            .toList(),
        requiredFields: ((json['required_fields'] as List?) ?? const [])
            .map((e) => '$e')
            .toList(),
        requiredLabels: ((json['required_labels'] as Map?) ?? const {})
            .map((k, v) => MapEntry('$k', '$v')),
      );
}

class SubscaleResult {
  const SubscaleResult({
    required this.short,
    required this.name,
    required this.rawScore,
    required this.scaledScore,
    required this.percentileRank,
  });

  final String short;
  final String name;
  final int rawScore;
  final int? scaledScore;
  final String percentileRank;

  factory SubscaleResult.fromJson(Map<String, dynamic> json) => SubscaleResult(
        short: json['short'] as String? ?? '',
        name: json['name'] as String? ?? '',
        rawScore: (json['raw_score'] as num?)?.toInt() ?? 0,
        scaledScore: (json['scaled_score'] as num?)?.toInt(),
        percentileRank: '${json['percentile_rank'] ?? 'n/a'}',
      );
}

/// The scoring report returned by POST /assessments (data.report).
class Report {
  const Report({
    required this.subscaleResults,
    required this.sumScaled,
    required this.compositePercentile,
    required this.autismIndex,
    required this.probability,
    required this.severity,
    required this.descriptor,
    required this.useSix,
  });

  final List<SubscaleResult> subscaleResults;
  final int sumScaled;
  final String compositePercentile;
  final int autismIndex;
  final String probability;
  final String severity;
  final String descriptor;
  final bool useSix;

  factory Report.fromJson(Map<String, dynamic> json) => Report(
        subscaleResults: ((json['subscale_results'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(SubscaleResult.fromJson)
            .toList(),
        sumScaled: (json['sum_scaled'] as num?)?.toInt() ?? 0,
        compositePercentile: '${json['composite_percentile'] ?? '-'}',
        autismIndex: (json['autism_index'] as num?)?.toInt() ?? 0,
        probability: '${json['probability'] ?? ''}',
        severity: '${json['severity'] ?? ''}',
        descriptor: '${json['descriptor'] ?? ''}',
        useSix: json['use_six'] as bool? ?? true,
      );
}

/// A row from GET /assessments (list shape).
class AssessmentSummary {
  const AssessmentSummary({
    required this.id,
    required this.individualName,
    required this.age,
    required this.gender,
    required this.dateOfRating,
    required this.isMute,
    required this.autismIndex,
    required this.severityLevel,
    required this.severityDesc,
    required this.createdAt,
  });

  final int id;
  final String individualName;
  final String age;
  final String gender;
  final String? dateOfRating;
  final bool isMute;
  final int autismIndex;
  final String severityLevel;
  final String severityDesc;
  final String? createdAt;

  factory AssessmentSummary.fromJson(Map<String, dynamic> json) =>
      AssessmentSummary(
        id: (json['id'] as num?)?.toInt() ?? 0,
        individualName: json['individual_name'] as String? ?? '',
        age: json['age'] as String? ?? '',
        gender: json['gender'] as String? ?? '',
        dateOfRating: json['date_of_rating'] as String?,
        isMute: json['is_mute'] as bool? ?? false,
        autismIndex: (json['autism_index'] as num?)?.toInt() ?? 0,
        severityLevel: json['severity_level'] as String? ?? '',
        severityDesc: json['severity_desc'] as String? ?? '',
        createdAt: json['created_at'] as String?,
      );
}

/// A full stored record (GET /assessments/{id}, and data.record on create).
class AssessmentDetail {
  const AssessmentDetail({
    required this.id,
    required this.individualName,
    required this.school,
    required this.raterName,
    required this.raterTitle,
    required this.dateOfBirth,
    required this.age,
    required this.gender,
    required this.grade,
    required this.examinerName,
    required this.examinerTitle,
    required this.dateOfRating,
    required this.raterKnownFor,
    required this.isMute,
    required this.raw,
    this.itemScores = const {},
    required this.scaled,
    required this.percentiles,
    required this.sumScaled,
    required this.compositePercentile,
    required this.autismIndex,
    required this.probability,
    required this.severityLevel,
    required this.severityDesc,
    required this.createdAt,
  });

  final int id;
  final String individualName;
  final String school;
  final String raterName;
  final String raterTitle;
  final String? dateOfBirth;
  final String age;
  final String gender;
  final String? grade;
  final String examinerName;
  final String examinerTitle;
  final String? dateOfRating;
  final String? raterKnownFor;
  final bool isMute;
  final Map<String, num> raw;

  /// The stored 0–3 mark of every item, keyed by subscale short code
  /// (RB, SI, …) in question order — what edit.php rebuilds its form from,
  /// and what the app prefills when editing a record.
  final Map<String, List<int>> itemScores;
  final Map<String, num> scaled;
  final Map<String, num> percentiles;
  final int sumScaled;
  final String compositePercentile;
  final int autismIndex;
  final String probability;
  final String severityLevel;
  final String severityDesc;
  final String? createdAt;

  factory AssessmentDetail.fromJson(Map<String, dynamic> json) {
    Map<String, num> numMap(dynamic value) {
      final out = <String, num>{};
      if (value is Map) {
        value.forEach((k, v) {
          if (v is num) {
            out['$k'] = v;
          } else if (v is String && double.tryParse(v) != null) {
            out['$k'] = double.parse(v);
          }
        });
      }
      return out;
    }

    final scores = (json['scores'] as Map?) ?? const {};

    // item_scores: [{RB: [0,1,…]}, {SI: […]}, …] — one single-key entry
    // per subscale, values in question order (same shape edit.php reads).
    final itemScores = <String, List<int>>{};
    final rawItems = json['item_scores'];
    if (rawItems is List) {
      for (final entry in rawItems) {
        if (entry is Map) {
          entry.forEach((key, value) {
            if (value is List) {
              itemScores['$key'] = [
                for (final v in value) (v is num ? v.toInt() : 0),
              ];
            }
          });
        }
      }
    }

    return AssessmentDetail(
      id: (json['id'] as num?)?.toInt() ?? 0,
      individualName: json['individual_name'] as String? ?? '',
      school: json['school'] as String? ?? '',
      raterName: json['rater_name'] as String? ?? '',
      raterTitle: json['rater_title'] as String? ?? '',
      dateOfBirth: json['date_of_birth'] as String?,
      age: json['age'] as String? ?? '',
      gender: json['gender'] as String? ?? '',
      grade: json['grade'] as String?,
      examinerName: json['examiner_name'] as String? ?? '',
      examinerTitle: json['examiner_title'] as String? ?? '',
      dateOfRating: json['date_of_rating'] as String?,
      raterKnownFor: json['rater_known_for'] as String?,
      isMute: json['is_mute'] as bool? ?? false,
      raw: numMap(scores['raw']),
      itemScores: itemScores,
      scaled: numMap(scores['scaled']),
      percentiles: numMap(scores['percentiles']),
      sumScaled: (scores['sum_scaled'] as num?)?.toInt() ?? 0,
      compositePercentile: '${scores['composite_percentile'] ?? '-'}',
      autismIndex: (scores['autism_index'] as num?)?.toInt() ?? 0,
      probability: '${scores['probability'] ?? ''}',
      severityLevel: '${scores['severity_level'] ?? ''}',
      severityDesc: '${scores['severity_desc'] ?? ''}',
      createdAt: json['created_at'] as String?,
    );
  }
}

const subscaleNames = {
  'RB': 'Restricted/Repetitive Behaviors',
  'SI': 'Social Interaction',
  'SC': 'Sensory Communication',
  'ER': 'Emotional/Regulatory',
  'CS': 'Cognitive Style',
  'MS': 'Maladaptive Speech',
};
