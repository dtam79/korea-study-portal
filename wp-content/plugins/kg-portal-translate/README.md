# KG Portal Browser Language 0.1.0

Automatically follows the browser's preferred language on public portal pages. `ko` and `en` use the original approved data and paths. For other supported languages, a WordPress server-side Gemini call translates **display text only**; official database values and review/publish records are untouched.

Supported language selection and a persistent manual override are displayed alongside existing KO/EN links. Static URL structure and search/approval logic are unchanged.

See `docs/CONFIGURATION_KO.md`. No live Gemini request is performed during installation or automatic tests.
