---
name: Bug report
about: A key is wrong, or the library raises or misbehaves
title: ''
labels: bug
assignees: ''
---

**What happened**

The input, the key you got and the key you expected. If one outlet is keyed wrongly but the code works, use the "Outlet map entry" template instead.

```
input:
got:
expected:
```

**Trace**

Paste the output of `outlet-key --explain '<url>'`.

**Environment**

- outlet-key version:
- map version (`outlet-key validate` prints it, or `OutletKey::mapVersion()`):
- PHP version:
- Custom map, overrides or Public Suffix List? (yes or no; if yes, the relevant part)

Please leave out personal data and any tokens in the URL.
