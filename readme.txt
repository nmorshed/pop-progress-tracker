=== POP Sales Tools ===
Contributors: pop
Tags: goals, progress, action plan, accountability, shortcode
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 4.0.0
License: GPLv2 or later

Private sales planning, playbook, and trailing twelve week dashboard tools for logged-in users.

== Installation ==

1. Activate POP Sales Action Plan.
2. Add `[pop_sales_action_plan]` to any WordPress page.
3. View the page while logged in.

To add the React Sales Playbook Builder, place `[pop_sales_playbook_builder]` on a page. Logged-in users can create multiple independent playbooks, complete the nine-step builder, and print or save the finished playbook as a PDF.

To add the React Trailing Twelve Week Dashboard, place `[pop_ttw_dashboard]` on a page. It includes an independently saved Trailing Twelve Week Dashboard and Team Execution Scorecard with automatic scoring, CSV export, and print layouts.

== TTW dashboard access ==

Administrators can always access the TTW dashboard. When the `popp_ttw_dashboard_allowed_user_ids` filter returns an empty array (the default), every logged-in user can access it. Return one or more user IDs from the filter to restrict access to those users.

Example:

`add_filter( 'popp_ttw_dashboard_allowed_user_ids', static function () { return array( 12, 34, 56 ); } );`

The same permission check protects the shortcode and every TTW dashboard REST endpoint.


== Caching ==

The plugin marks shortcode pages and all authenticated API responses as private and non-cacheable. Its script and stylesheet versions are based on their modification time, so changed assets receive a new URL automatically. Exclude pages containing this shortcode from any host or CDN cache that ignores WordPress no-cache headers.

== Team progress ==

Administrators can view every user’s active or completed action plan in read-only mode. To grant a group leader access to specific students, return their WordPress user IDs from the `popp_action_plan_viewable_student_ids` filter. The API enforces this access server-side; a leader cannot save, submit, archive, or create a student’s action plan.

== Scoring ==

Every weekly activity plus commitment completion and the next commitment have equal weight. The final percentage is rounded up to the next integer and capped at 100.

On the TTW dashboard, each week's Execution Score is the percentage of reported metrics that met or exceeded their goal. On the Team Execution Scorecard, the three tactics, kept commitment, and new commitment are worth 20% each; the team score averages named reps who have started reporting.

== Data removal ==

Tables are retained on uninstall to prevent accidental data loss. Define `POPP_REMOVE_DATA` as true before uninstalling to remove them.
