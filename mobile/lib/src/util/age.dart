/// Age helpers for the rating form.
///
/// Mirrors the web form's `autoFillAge()` in includes/rating_form.php:
/// the age is measured at the date of rating (or today when no rating date
/// has been chosen yet) and only accepted when it lands in 0..120 years.
library;

/// Whole years between [dob] and [reference] (defaults to today), or `null`
/// when the pair doesn't describe a plausible age (future birth date, or a
/// result outside 0..120).
int? ageAt(DateTime dob, [DateTime? reference]) {
  final ref = reference ?? DateTime.now();
  if (dob.isAfter(ref)) return null;

  var years = ref.year - dob.year;
  final months = ref.month - dob.month;
  if (months < 0 || (months == 0 && ref.day < dob.day)) {
    years--;
  }
  return (years >= 0 && years <= 120) ? years : null;
}
