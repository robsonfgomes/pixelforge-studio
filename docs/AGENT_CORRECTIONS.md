# Agent corrections

One line per correction: what the agent had done → what I asked to change → why.

- Wrote chat replies in English → asked to write them in Portuguese → reason pending (CLAUDE.md allows explanations in Portuguese).
- Planned to store the friendly failure text in `error_message` → keep Pixelforge's original message (or a technical description for our own codes) in the database and map the code to friendly text through a backend enum applied by `GenerationResource` → no UI copy in the database; the raw message is useful for debugging.
