# Security policy

## Supported versions

The latest release on the `0.1` line gets security fixes.

## Reporting a vulnerability

Email hello@indianopinion.org with "outlet-key security" in the subject. Do not open a public issue.

Include the version, a minimal input that shows the problem and what you expected. We acknowledge reports within 3 working days and aim to ship a fix or a mitigation within 30 days. We credit reporters in the release notes unless you ask us not to.

## Scope

outlet-key is a pure function: no network, no file writes, no shell. Useful reports include an input that makes keying raise outside the documented exceptions, hang, or use unbounded memory; a key that leaks user info from a URL; and a way for URL input to reach a regular expression as a pattern.

A map loaded from an untrusted source is out of scope: a map is code, and its patterns run on every URL. Load maps only from sources you trust.
