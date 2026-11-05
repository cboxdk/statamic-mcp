---
title: Collection trees and entry parents
description: Read collection hierarchies and move entries with scoped editor permissions.
weight: 45
---

# Collection trees and entry parents

Collection metadata does not describe the page hierarchy. Request a site-specific
collection tree with:

```json
{"action":"get","resource_type":"collection","handle":"pages","site":"default","include_tree":true}
```

The response adds `site` and `tree` to `collection`. Collection listing includes
only collections the current user may view, before totals and pagination. Editors
receive basic editing metadata; collection configuration remains an administrator
operation.

To create or update an entry under a parent, pass `data.parent` to
`statamic-entries`. Use an entry ID from the same collection and site, or `null`
for the top level. Omit the field to leave the existing parent unchanged.

```json
{"action":"update","collection":"pages","id":"CHILD_ID","site":"default","data":{"parent":"PARENT_ID"}}
```

Alternatively, move a branch through `statamic-structures`:

```json
{"action":"update","resource_type":"collection","handle":"pages","site":"default","data":{"entry":"CHILD_ID","parent":"PARENT_ID"}}
```

The structures router is discoverable with `search_tools` and callable through
`execute_tools`. This operation moves the existing subtree; it does not accept a
replacement tree or mix a move with collection settings. Sibling-position editing
is outside this API. The returned parent is the saved parent; top-level entries
return `null`, including entries immediately below a configured collection root.
Creating the first entry in a collection with a root also accepts `parent: null`.
An existing root accepts `null` as an unchanged parent; moving it under another
entry or assigning itself as its parent is refused.

Moves require collection reorder rights, permission to edit the moved entries,
an accessible site and parent, the entries/structures resource policies and both
`entries:write` and `structures:write` scopes. Tree reads require both read scopes.
Invalid IDs, cross-site/collection parents, cycles, root moves, excessive depth and
URI collisions fail explicitly. Existing authentication and confirmation behavior
is unchanged.
Field deny lists also apply to parent metadata and tree reads, including for
administrators and local CLI callers. Both domains' field policies apply to tree
data. If an update combines fields and a parent, collision checks use the proposed
field values before saving content, including fields used by a custom route.

For published entries with revisions enabled, submit a parent-only move separately
from content edits. Moving the structure takes effect immediately; it must not be
silently combined with an unpublished content working copy.

MCP moves use a collection/site cache lock, detect a tree file changed during
validation, and verify the resulting parent. Content and structure files are not
a cross-file transaction; the MCP lock does not serialize CP or external file
writes. Full-tree replacement and concurrent external editing are outside the
operation's guarantees.
