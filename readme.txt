=== POP Sales Action Plan ===
Contributors: pop
Tags: goals, progress, action plan, accountability, shortcode
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later

A private 12-week sales action plan for logged-in users.

== Installation ==

1. Activate POP Sales Action Plan.
2. Add `[pop_sales_action_plan]` to any WordPress page.
3. View the page while logged in.

To add the React Sales Playbook Builder, place `[pop_sales_playbook_builder]` on a page. Logged-in users can create multiple independent playbooks, complete the nine-step builder, and print or save the finished playbook as a PDF.


== Caching ==

The plugin marks shortcode pages and all authenticated API responses as private and non-cacheable. Its script and stylesheet versions are based on their modification time, so changed assets receive a new URL automatically. Exclude pages containing this shortcode from any host or CDN cache that ignores WordPress no-cache headers.

== Team progress ==

Administrators can view every user’s active or completed action plan in read-only mode. To grant a group leader access to specific students, return their WordPress user IDs from the `popp_action_plan_viewable_student_ids` filter. The API enforces this access server-side; a leader cannot save, submit, archive, or create a student’s action plan.

== Scoring ==

Every weekly activity plus commitment completion and the next commitment have equal weight. The final percentage is rounded up to the next integer and capped at 100.

== Data removal ==

Tables are retained on uninstall to prevent accidental data loss. Define `POPP_REMOVE_DATA` as true before uninstalling to remove them.
