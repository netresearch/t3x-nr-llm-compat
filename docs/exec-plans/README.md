# Execution plans

Multi-session work plans for agents. A plan records goal, constraints, step checklist and current state so a fresh session can resume without re-deriving context.

- `active/` — plans currently being executed (create the directory with the first plan)
- `completed/` — finished plans kept for reference

Keep plans as Markdown checklists; update the state in the same commit as the work it describes.
