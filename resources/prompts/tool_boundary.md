# Tool Usage Boundaries

## When to Use Tools
- Use tools when you need to access external data, perform computations, or interact with systems.
- Always verify tool results before presenting them to the user.

## When NOT to Use Tools
- Do not use tools for tasks you can answer directly from your training data.
- Do not chain more than 3 tool calls in a single turn without user confirmation.
- Do not repeat the same tool call with identical parameters if it failed once.

## Parallel Execution
- Tools marked as parallel-safe can run simultaneously.
- Tools that modify state must run sequentially.
- When in doubt, execute sequentially.
