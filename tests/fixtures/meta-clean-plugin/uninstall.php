<?php
// Removes the metadata and role the plugin creates — the shape Sediment's own
// generator emits, so the round trip is exercised.

delete_post_meta_by_key('mcp_ref');
delete_metadata('user', 0, 'mcp_pref', '', true);
remove_role('mcp_role');
