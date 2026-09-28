---
name: Bug report
about: Something isn't working as expected
title: '[Bug] '
labels: ['bug', 'needs-triage']
assignees: ''
---

<!--
  Thanks for taking the time to report a bug!
  Please fill in every section. Incomplete reports get closed.
-->

## Summary

A clear, one-paragraph description of what's broken.

## Steps to reproduce

1. Go to '...'
2. Click on '...'
3. Scroll to '...'
4. See error

Provide the smallest possible sequence that triggers the bug.

## Expected behaviour

What you expected to happen.

## Actual behaviour

What actually happened. Include the exact error message if there
was one.

## Screenshots or screen recordings

If applicable, drag screenshots or a short video here. A picture is
worth a thousand log lines.

## Environment

| Field | Value |
|---|---|
| **Connect24 version** | <!-- shown in the topbar, e.g. v1.0.0 --> |
| **RouterOS version** | <!-- output of `/system/resource print`, e.g. 7.14.3 --> |
| **Router model** | <!-- e.g. hEX S, RB4011, CHR --> |
| **Browser** | <!-- e.g. Chrome 121, Firefox 122, Safari 17.3 --> |
| **Operating system** | <!-- e.g. macOS 14.2, Windows 11, Ubuntu 22.04 --> |
| **Deployment** | <!-- localhost, VPS, shared hosting, Docker --> |
| **PHP version** | <!-- `php -v`, e.g. 8.2.15 --> |

## Does it happen in demo mode?

Try opening the same page with `?demo=1` appended to the URL:

- [ ] Yes, it happens in demo mode too (frontend bug)
- [ ] No, it only happens with a real router (backend / router bug)
- [ ] I haven't tried demo mode

## Console output

Open DevTools → **Console** tab and paste any red errors here. If
there are none, say "no console errors".
