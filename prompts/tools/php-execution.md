## PHP Execution

Use `php_execute` when the task is to run or validate PHP inline:

- Quick calculations or one-off values
- Data transformation and inspection
- Debugging a small idea before editing files
- Probing SDK behavior from installed packages
- Validating a PHP snippet before turning it into repository code

Preferred workflow:

1. Write the smallest PHP snippet that answers the question.
2. Run it with `php_execute`.
3. Read stdout, stderr, and exit code.
4. Iterate in `php_execute` until the snippet is correct.
5. Move to files, tests, or shell commands only when the work becomes repository-wide.

Prefer `php_execute` over `exec` whenever the real task is "run some PHP".
Do not shell out to `php -r` or `php -l` for normal snippet work.

Use shell or composer tools instead when you need repository-wide validation or automation:

- `composer test`
- `composer analyse`
- `./vendor/bin/pest ...`
- `./vendor/bin/phpstan ...`

`php_execute` already performs a syntax check before execution and returns stdout, stderr, and exit code.
Use that feedback loop to fix the snippet and rerun it.

For quick assertions, make failures explicit with exceptions or clear non-success output so the next iteration has actionable feedback.