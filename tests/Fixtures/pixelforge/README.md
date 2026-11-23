# Pixelforge fixtures

Copied from https://pixelforge.promptedit.dev/docs. Generation objects, the event
envelope, pricing, usage and the `validation_failed` error body are the docs'
examples verbatim (a `processing`/`failed`/`ping` variant is derived from them).
Only the validation error message appears in the docs; the other error messages are
paraphrased from the docs' error table, with the documented `type` and `code`.

Load them with `Tests\Fixtures\PixelforgeFixtures::load('generation.succeeded', $overrides)`.
