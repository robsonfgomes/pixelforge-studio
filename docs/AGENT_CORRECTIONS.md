# Agent corrections

One line per correction: what the agent had done → what I asked to change → why.

- Wrote chat replies in English → asked to write them in Portuguese → reason pending (CLAUDE.md allows explanations in Portuguese).
- Planned to store the friendly failure text in `error_message` → keep Pixelforge's original message (or a technical description for our own codes) in the database and map the code to friendly text through a backend enum applied by `GenerationResource` → no UI copy in the database; the raw message is useful for debugging.
- Proposed a polling chain with no uniqueness guard → make `PollGenerationJob` `ShouldBeUniqueUntilProcessing` by generation id and dispatch poll attempt 1 only when `SubmitGeneration` sets `submitted_at` itself → never more than one polling chain per generation, while a running job can still dispatch its next attempt.
- Proposed the M3 flow without a rule about external calls and locks → never call Pixelforge inside a transaction or while holding a row lock; call first, then lock, re-read and apply → reason pending.
- Proposed failure messages that name Pixelforge → provider-neutral copy, and update the existing messages that name it → users don't need to know the provider.
- Proposed answering 400 when the webhook secret is missing → answer 500 with an error log → a missing secret is our misconfiguration, not a bad request.
