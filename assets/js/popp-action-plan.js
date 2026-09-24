(function () {
  'use strict';

  var root = document.querySelector('[data-popp-action-plan]');
  if (!root || !window.POPP_ACTION_PLAN || !window.wp || !window.wp.element) return;

  var e = window.wp.element.createElement;
  var useEffect = window.wp.element.useEffect;
  var useState = window.wp.element.useState;
  var config = window.POPP_ACTION_PLAN;
  var menu = [
    ['overview', '⌂', 'Overview'], ['current', '✓', 'Current week'], ['plan', '◎', 'Tactical plan'],
    ['rocks', '◆', 'Rocks'], ['history', '↗', 'Score history'], ['instructions', 'i', 'Instructions']
  ];

  function api(path, options) {
    options = options || {};
    return fetch(config.root + path, {
      method: options.method || 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: Object.assign({ 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cache-Control': 'no-store', 'X-WP-Nonce': config.nonce }, options.headers || {}),
      body: options.body ? JSON.stringify(options.body) : undefined
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (json) {
        if (!response.ok) { var error = new Error(json.message || 'Something went wrong. Please try again.'); error.status = response.status; throw error; }
        return json;
      });
    });
  }

  function clone(value) { return JSON.parse(JSON.stringify(value)); }
  function message(error) { return error && error.message ? error.message : 'Something went wrong. Please try again.'; }
  function currentWeek(data) { var index = data.weeks.findIndex(function (week) { return week.status !== 'submitted'; }); return index === -1 ? null : index; }
  function weekDate(start, index) { var date = new Date(start + 'T12:00:00'); date.setDate(date.getDate() + index * 7); return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); }
  function statusLabel(status) { return status === 'completed' ? 'Completed' : status === 'archived' ? 'Archived' : 'Active'; }

  function Button(props) {
    return e('button', { type: props.type || 'button', className: 'popp-button' + (props.variant ? ' popp-button--' + props.variant : ''), disabled: !!props.disabled, onClick: props.onClick }, props.children);
  }
  function Notice(props) { return props.children ? e('div', { className: 'popp-notice popp-notice--' + props.kind, role: props.kind === 'error' ? 'alert' : 'status' }, props.children) : null; }
  function Status(props) { return e('span', { className: 'popp-status popp-status--' + props.status }, e('i'), statusLabel(props.status)); }

  function App() {
    var statePlans = useState([]), plans = statePlans[0], setPlans = statePlans[1];
    var statePlan = useState(null), plan = statePlan[0], setPlan = statePlan[1];
    var stateView = useState('overview'), view = stateView[0], setView = stateView[1];
    var stateLoading = useState(true), loading = stateLoading[0], setLoading = stateLoading[1];
    var stateError = useState(''), error = stateError[0], setError = stateError[1];
    var stateNotice = useState(''), notice = stateNotice[0], setNotice = stateNotice[1];
    var stateTeam = useState(null), team = stateTeam[0], setTeam = stateTeam[1];
    var stateMenu = useState(false), menuOpen = stateMenu[0], setMenuOpen = stateMenu[1];
    var stateArchive = useState(null), pendingArchive = stateArchive[0], setPendingArchive = stateArchive[1];

    function loadPlans() {
      return api('action-plans').then(function (items) { setPlans(items); return items; });
    }
    useEffect(function () {
      loadPlans().then(function (items) {
        var active = items.find(function (item) { return item.status === 'active'; });
        if (active) return api('action-plans/' + active.id).then(setPlan);
      }).catch(function (err) { setError(message(err)); }).finally(function () { setLoading(false); });
    }, []);
    useEffect(function () {
      if (!notice) return undefined;
      var timer = window.setTimeout(function () { setNotice(''); }, 3000);
      return function () { window.clearTimeout(timer); };
    }, [notice]);
    useEffect(function () {
      if (!error) return undefined;
      var timer = window.setTimeout(function () { setError(''); }, 3000);
      return function () { window.clearTimeout(timer); };
    }, [error]);

    function openPlan(id) {
      setLoading(true); setError('');
      api('action-plans/' + id).then(function (item) { setPlan(item); setView('overview'); setMenuOpen(false); }).catch(function (err) { setError(message(err)); }).finally(function () { setLoading(false); });
    }
    function createPlan(title) {
      return api('action-plans', { method: 'POST', body: { title: title } }).then(function (item) { setPlan(item); setView('plan'); setNotice('Action plan created. Complete Week 0 to get started.'); return loadPlans(); });
    }
    function startNewPlan() {
      var active = plans.find(function (item) { return item.status === 'active'; });
      if (active) {
		setPendingArchive(active);
        setView('archive-confirm');
        setMenuOpen(false);
        return;
      }
      setPlan(null);
      setView('overview');
      setMenuOpen(false);
    }
    function openTeam() {
      setError(''); setView('team'); setMenuOpen(false);
      if (team !== null) return;
      loadTeam(1, '');
    }
    function loadTeam(page, search) {
      setTeam(null);
      return api('team?page=' + encodeURIComponent(page) + '&per_page=20&search=' + encodeURIComponent(search || '')).then(setTeam).catch(function (err) { setError(message(err)); setView('overview'); });
    }
    function updatePlan(next) { setPlan(next); setPlans(function (items) { return items.map(function (item) { return item.id === next.id ? Object.assign({}, item, { title: next.title, status: next.status, updatedAt: next.updatedAt }) : item; }); }); }
    function archivePlan(id) {
      return api('action-plans/' + id, { method: 'DELETE' }).then(function () { setPlan(null); setView('overview'); setNotice('Action plan archived.'); return loadPlans(); });
    }

    if (loading) return e('div', { className: 'popp-loading', role: 'status' }, 'Loading your sales action plan…');
    return e('div', { className: 'popp-shell' },
      e(Sidebar, { plans: plans, plan: plan, view: view, setView: function (next) { setView(next); setMenuOpen(false); }, openPlan: openPlan, openTeam: openTeam, startNewPlan: startNewPlan, menuOpen: menuOpen, setMenuOpen: setMenuOpen }),
      e('main', { className: 'popp-main' },
        e(Notice, { kind: 'success' }, notice), e(Notice, { kind: 'error' }, error),
        view === 'archive-confirm' ? e(ArchiveConfirm, { plan: pendingArchive, cancel: function () { setPendingArchive(null); setView('overview'); }, confirm: function () { archivePlan(pendingArchive.id).then(function () { setPendingArchive(null); }).catch(function (err) { setError(message(err)); setView('overview'); }); } }) : view === 'team' ? e(Team, { team: team, openPlan: openPlan, loadTeam: loadTeam }) : e(Workspace, { plan: plan, plans: plans, view: view, setView: setView, createPlan: createPlan, updatePlan: updatePlan, archivePlan: archivePlan, setError: setError, setNotice: setNotice })
      )
    );
  }

  function Sidebar(props) {
    return e('aside', { className: 'popp-sidebar' + (props.menuOpen ? ' is-open' : '') },
      e('div', { className: 'popp-brand' }, e('span', null, 'POP'), e('strong', null, 'Sales Action Plan')),
      e('button', { type: 'button', className: 'popp-mobile-menu', onClick: function () { props.setMenuOpen(!props.menuOpen); }, 'aria-expanded': props.menuOpen }, '☰ Menu'),
      e('div', { className: 'popp-sidebar-content' },
        e('nav', { 'aria-label': 'Sales Action Plan' }, menu.map(function (item) { return e('button', { type: 'button', key: item[0], className: props.view === item[0] ? 'is-active' : '', onClick: function () { props.setView(item[0]); } }, e('i', null, item[1]), item[2]); }),
          config.canViewTeam ? e('button', { type: 'button', className: props.view === 'team' ? 'is-active' : '', onClick: props.openTeam }, e('i', null, '♙'), 'Team progress') : null
        ),
        e('div', { className: 'popp-tracker-nav' }, e('p', null, 'Action Plans'),
          props.plans.length ? props.plans.map(function (item) { return e('button', { type: 'button', key: item.id, className: props.plan && props.plan.id === item.id ? 'is-selected' : '', onClick: function () { props.openPlan(item.id); } }, e('span', { className: 'popp-status-dot popp-status-dot--' + item.status }), e('b', null, item.title), e('small', null, statusLabel(item.status))); }) : e('span', { className: 'popp-empty-trackers' }, 'No action plans yet'),
          e('button', { type: 'button', className: 'popp-new-tracker', onClick: props.startNewPlan }, '+ New Action Plan')
        )
      )
    );
  }

  function Workspace(props) {
    var plan = props.plan;
    var ownerName = plan && plan.owner ? plan.owner.name : 'Your action plan';
    return e('div', { className: 'popp-workspace' },
      props.view === 'overview' ? e('header', { className: 'popp-heading' }, e('span', null, plan && plan.readOnly ? 'Student progress — read only' : '12-Week Execution Score'), !plan ? e('h1', null, 'Your action plan') : null, plan && plan.readOnly ? e('p', { className: 'popp-read-only' }, 'Viewing ' + ownerName + '’s action plan. Changes are disabled.') : null) : null,
      props.view === 'overview' ? e(Overview, props) : !plan ? e(EmptyState, { createPlan: props.createPlan }) :
        props.view === 'plan' ? e(PlanSetup, props) : props.view === 'rocks' ? e(Rocks, props) : props.view === 'current' ? e(CurrentWeek, props) : props.view === 'history' ? e(History, { plan: plan }) : e(Instructions)
    );
  }

  function NewPlan(props) {
    var state = useState(''), title = state[0], setTitle = state[1];
    var loading = useState(false), creating = loading[0], setCreating = loading[1];
    return e('form', { className: 'popp-new-plan', onSubmit: function (event) { event.preventDefault(); setCreating(true); props.createPlan(title).catch(function (err) { props.setError(message(err)); }).finally(function () { setCreating(false); }); } },
      e('label', null, 'Action plan name', e('input', { required: true, maxLength: 190, value: title, placeholder: 'e.g. Q1 Commercial Growth', onChange: function (event) { setTitle(event.target.value); } })),
      e(Button, { type: 'submit', disabled: creating }, creating ? 'Creating…' : 'Set up Week 0')
    );
  }
  function EmptyState(props) { return e('section', { className: 'popp-empty-state' }, e('div', { className: 'popp-empty-icon' }, '◎'), e('h2', null, 'Start your first 12-week action plan'), e('p', null, 'Define your lead activities, three Rocks, and your first commitment.'), e(NewPlan, props)); }
  function Overview(props) {
    if (!props.plan) return e(EmptyState, props);
    var plan = props.plan, week = currentWeek(plan.data), submitted = plan.data.weeks.filter(function (item) { return item.status === 'submitted'; });
    var average = submitted.length ? Math.ceil(submitted.reduce(function (total, item) { return total + item.score; }, 0) / submitted.length) : 0;
    return e('section', { className: 'popp-overview' },
      e('div', { className: 'popp-overview-title' }, e('div', null, e('h2', null, plan.title), e(Status, { status: plan.status })), !plan.readOnly && plan.status === 'active' ? e(Button, { onClick: function () { props.setView('current'); } }, week === null ? 'View score history' : 'Open Week ' + (week + 1)) : null),
      e('div', { className: 'popp-stat-grid' }, e(Stat, { label: 'Current week', value: week === null ? 'Complete' : 'Week ' + (week + 1) }), e(Stat, { label: 'Score to date', value: average + '%' }), e(Stat, { label: 'Rocks', value: plan.data.rocks.filter(Boolean).length + ' of 3' })),
      e('section', { className: 'popp-card' }, e('h3', null, week === null ? 'Action plan complete' : 'What’s next'), e('p', null, week === null ? 'All 12 weeks have been submitted. Review the score history to see the full execution record.' : 'Complete your current-week check-in: report the actuals, score the commitment, and set the next commitment.'))
    );
  }
  function Stat(props) { return e('div', { className: 'popp-stat' }, e('span', null, props.label), e('strong', null, props.value)); }

  function ArchiveConfirm(props) {
    return e('section', { className: 'popp-dialog-wrap', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'popp-archive-title' },
      e('div', { className: 'popp-dialog' }, e('span', { className: 'popp-dialog-icon' }, '!'), e('h2', { id: 'popp-archive-title' }, 'Archive your current action plan?'),
        e('p', null, 'You can only have one active action plan. Archive “' + (props.plan ? props.plan.title : 'your current plan') + '” to create a new one. Archived plans remain available to view, but are locked.'),
        e('div', { className: 'popp-dialog-actions' }, e(Button, { variant: 'quiet', onClick: props.cancel }, 'Keep current plan'), e(Button, { onClick: props.confirm }, 'Archive and create new plan'))
      )
    );
  }

  function usePlanEditor(props) {
    var flags = useState(false), dirty = flags[0], setDirty = flags[1];
    var savingState = useState(false), saving = savingState[0], setSaving = savingState[1];
    var editable = !props.plan.readOnly && props.plan.status === 'active';
    useEffect(function () { function leave(event) { if (!dirty || saving) return; event.preventDefault(); event.returnValue = ''; } window.addEventListener('beforeunload', leave); return function () { window.removeEventListener('beforeunload', leave); }; }, [dirty, saving]);
    function change(mutator) { if (!editable) return; var next = clone(props.plan); mutator(next); props.updatePlan(next); props.setError(''); props.setNotice(''); setDirty(true); }
    function save(complete) { if (!editable) return Promise.resolve(); setSaving(true); props.setError(''); return api('action-plans/' + props.plan.id, { method: 'PUT', body: { title: props.plan.title, revision: props.plan.revision, data: props.plan.data, completeWeek: !!complete } }).then(function (saved) { props.updatePlan(saved); setDirty(false); props.setNotice(complete ? 'Week submitted successfully.' : 'All changes saved.'); }).catch(function (err) { props.setError(message(err)); }).finally(function () { setSaving(false); }); }
    return { change: change, save: save, dirty: dirty, saving: saving, editable: editable };
  }

  function PlanSetup(props) {
    var edit = usePlanEditor(props), plan = props.plan, data = plan.data;
    var dateState = useState(''), dateWarning = dateState[0], setDateWarning = dateState[1];
    var configurationLocked = data.weeks.some(function (week) { return week.status === 'submitted'; });
    function isFriday(value) { return value && new Date(value + 'T12:00:00').getDay() === 5; }
    return e('section', null, e(SectionHeader, { title: 'Tactical plan', copy: 'Set the lead activities and weekly minimums that drive your 12-week execution system.', edit: edit }),
      e('section', { className: 'popp-card' }, e('div', { className: 'popp-form-grid' },
        e('label', null, 'Action plan name', e('input', { disabled: !edit.editable, value: plan.title, onChange: function (event) { edit.change(function (next) { next.title = event.target.value; }); } })),
        e('label', null, 'Week 1 ending (Friday)', e('input', { disabled: !edit.editable || configurationLocked, type: 'date', value: data.weekOneEnding, onChange: function (event) { var value = event.target.value; setDateWarning(value && !isFriday(value) ? 'Week 1 must end on a Friday. Please choose a Friday before saving.' : ''); edit.change(function (next) { next.data.weekOneEnding = value; }); } }), dateWarning ? e('span', { className: 'popp-date-warning', role: 'alert' }, dateWarning) : null)
      ), e('h3', { className: 'popp-plan-subheading' }, 'Weekly lead activities'), e('p', { className: 'popp-plan-description' }, 'Fully custom per Progress Tracker — no fixed catalog. Jim\'s methodology calls for 3–5 recurring activities, so at least 3 are required here — add more if this run needs them.'), data.activities.map(function (activity, index) { return e('div', { className: 'popp-activity-row', key: index },
        e('label', null, 'Activity ' + (index + 1), e('input', { disabled: !edit.editable, value: activity.name, onChange: function (event) { edit.change(function (next) { next.data.activities[index].name = event.target.value; }); } })),
        e('label', null, 'Minimum / week', e('input', { disabled: !edit.editable || configurationLocked, type: 'number', min: '0', step: 'any', value: activity.minimum, onChange: function (event) { edit.change(function (next) { next.data.activities[index].minimum = event.target.value; }); } })),
        edit.editable && data.activities.length > 3 ? e(Button, { variant: 'quiet', disabled: configurationLocked, onClick: function () { edit.change(function (next) { next.data.activities.splice(index, 1); next.data.weeks.forEach(function (week) { week.actuals.splice(index, 1); }); }); } }, 'Remove') : null
      ); }), edit.editable && data.activities.length < 5 ? e(Button, { variant: 'quiet', disabled: configurationLocked, onClick: function () { edit.change(function (next) { next.data.activities.push({ name: '', minimum: '' }); next.data.weeks.forEach(function (week) { week.actuals.push(''); }); }); } }, 'Add activity') : null
      ), !plan.readOnly ? e('div', { className: 'popp-bottom-actions' }, e(Button, { variant: 'quiet', disabled: plan.status === 'archived', onClick: function () { if (window.confirm('Archive this action plan? You can still view it, but it will be locked.')) props.archivePlan(plan.id).catch(function (err) { props.setError(message(err)); }); } }, 'Archive action plan'), e(Button, { disabled: !edit.dirty || edit.saving || !edit.editable || !!dateWarning, onClick: function () { edit.save(false); } }, edit.saving ? 'Saving…' : 'Save tactical plan')) : null
    );
  }

  function Rocks(props) {
    var edit = usePlanEditor(props), data = props.plan.data;
    var rocksLocked = data.weeks.some(function (week) { return week.status === 'submitted'; });
    return e('section', null, e(SectionHeader, { title: 'Rocks', copy: 'Keep three meaningful priorities visible throughout the 12-week cycle.', edit: edit }),
      e('section', { className: 'popp-card' }, data.rocks.map(function (rock, index) { return e('label', { className: 'popp-rock', key: index }, 'Rock ' + (index + 1), e('textarea', { disabled: !edit.editable || rocksLocked, rows: 3, value: rock, placeholder: 'Your 90-day priority', onChange: function (event) { edit.change(function (next) { next.data.rocks[index] = event.target.value; }); } })); }),
      !props.plan.readOnly ? e('div', { className: 'popp-bottom-actions' }, e(Button, { disabled: !edit.dirty || edit.saving || !edit.editable, onClick: function () { edit.save(false); } }, edit.saving ? 'Saving…' : 'Save Rocks')) : null)
    );
  }

  function hasNumber(value) { return value !== '' && value !== null && value !== undefined && !Number.isNaN(Number(value)); }
  function weeklyPreview(data, week) {
    var hits = week.commitmentCompleted ? 1 : 0;
    data.activities.forEach(function (activity, index) { if (hasNumber(week.actuals[index]) && activity.minimum !== '' && Number(week.actuals[index]) >= Number(activity.minimum)) hits++; });
    if (week.nextCommitment.trim() !== '') hits++;
    return { score: Math.ceil(hits / (data.activities.length + 2) * 100) };
  }
  function scoreTone(score) { return score >= 80 ? 'high' : score >= 60 ? 'mid' : 'low'; }
  function submittedScoreToDate(data) {
    var submitted = data.weeks.filter(function (week) { return week.status === 'submitted'; });
    return submitted.length ? Math.ceil(submitted.reduce(function (total, week) { return total + week.score; }, 0) / submitted.length) : null;
  }

  function CurrentWeek(props) {
    var edit = usePlanEditor(props), plan = props.plan, data = plan.data, index = currentWeek(data);
    if (index === null) return e('section', null, e(SectionHeader, { title: 'Current week', copy: 'This action plan is complete.', edit: edit }), e('section', { className: 'popp-card' }, e('h2', null, 'All 12 weeks submitted'), e('p', null, 'Review the Score history for your final execution results.')));
    var week = data.weeks[index], preview = weeklyPreview(data, week), scoreToDate = submittedScoreToDate(data);
    var priorCommitment = index > 0 ? data.weeks[index - 1].nextCommitment.trim() : '';
    return e('section', null, e(SectionHeader, { title: 'Current week', copy: 'Week ' + (index + 1) + ' ends ' + weekDate(data.weekOneEnding, index) + '.', edit: edit }),
      e('section', { className: 'popp-card' }, e('section', { className: 'popp-score-banner', 'aria-label': 'Official execution score' },
        e('div', { className: 'popp-score-banner-title' }, 'Official Execution Score'),
        e('div', { className: 'popp-score-banner-grid' },
          e('div', { className: 'popp-score-banner-item' }, e('strong', { className: 'popp-score-value popp-score-value--' + scoreTone(preview.score) }, preview.score + '%'), e('span', null, 'This week (live)')),
          e('div', { className: 'popp-score-banner-item' }, e('strong', { className: 'popp-score-value' + (scoreToDate === null ? ' popp-score-value--empty' : ' popp-score-value--' + scoreTone(scoreToDate)) }, scoreToDate === null ? '—' : scoreToDate + '%'), e('span', null, 'Progress Tracker score to date (before this week)'))
        )
      ),
      e('section', { className: 'popp-prior-commitment', 'aria-labelledby': 'popp-prior-commitment-title' },
        e('h3', { id: 'popp-prior-commitment-title', className: 'popp-current-heading' }, 'Last week\'s commitment'),
        e('p', { className: priorCommitment ? '' : 'popp-muted' }, priorCommitment || 'No previous commitment recorded.')
      ),
      e('fieldset', { className: 'popp-commitment-status' }, e('legend', null, 'Completed? (yes/no)'),
        e('div', { className: 'popp-choice-grid' },
          e('label', { className: 'popp-choice popp-choice--yes' }, e('input', { disabled: !edit.editable, type: 'radio', name: 'commitment', checked: week.commitmentCompleted === true, onChange: function () { edit.change(function (next) { next.data.weeks[index].commitmentCompleted = true; }); } }), e('span', null, 'Yes')),
          e('label', { className: 'popp-choice popp-choice--no' }, e('input', { disabled: !edit.editable, type: 'radio', name: 'commitment', checked: week.commitmentCompleted === false, onChange: function () { edit.change(function (next) { next.data.weeks[index].commitmentCompleted = false; }); } }), e('span', null, 'No'))
        )
      ),
      e('section', { className: 'popp-lead-activities', 'aria-labelledby': 'popp-lead-activities-title' },
        e('h3', { id: 'popp-lead-activities-title', className: 'popp-current-heading' }, 'Lead activities — actual vs. minimum'),
        e('p', null, 'All-or-nothing, same as the official score above: hitting 4 out of a minimum of 5 still counts as a miss. This table is just for entering the numbers — the badge in the last column is the only readout, on purpose.'),
        e('div', { className: 'popp-results' }, data.activities.map(function (activity, activityIndex) { var actual = week.actuals[activityIndex]; var hasActual = hasNumber(actual); var hasGoal = activity.minimum !== ''; var hit = hasActual && hasGoal && Number(actual) >= Number(activity.minimum); var resultStatus = !hasActual || !hasGoal ? 'empty' : hit ? 'hit' : 'miss'; var resultLabel = resultStatus === 'hit' ? '✓ HIT' : resultStatus === 'miss' ? '✗ MISS' : '—'; return e('div', { className: 'popp-result-row', key: activityIndex }, e('span', { className: 'popp-result-name' }, activity.name || 'Activity ' + (activityIndex + 1)), e('span', { className: 'popp-result-minimum' }, 'Minimum: ' + (hasGoal ? activity.minimum : 'not set')), e('label', { className: 'popp-result-input' }, e('span', { className: 'screen-reader-text' }, 'Actual result for ' + (activity.name || 'Activity ' + (activityIndex + 1))), e('input', { disabled: !edit.editable, type: 'number', min: '0', step: 'any', value: actual === undefined ? '' : actual, placeholder: 'Actual', onChange: function (event) { edit.change(function (next) { next.data.weeks[index].actuals[activityIndex] = event.target.value; }); } })), e('span', { className: 'popp-result-status popp-result-status--' + resultStatus, 'aria-label': resultStatus === 'hit' ? 'Hit minimum' : resultStatus === 'miss' ? 'Missed minimum' : 'No result entered' }, resultLabel)); }))
      ),
      e('section', { className: 'popp-next-commitment', 'aria-labelledby': 'popp-next-commitment-title' },
        e('h3', { id: 'popp-next-commitment-title', className: 'popp-current-heading' }, 'Commitment for next week'),
        e('p', { id: 'popp-next-commitment-description' }, 'One sentence. Starts with a verb. Executed as written.'),
        e('textarea', { disabled: !edit.editable, rows: 3, value: week.nextCommitment, placeholder: 'One sentence. Starts with a verb. Executed as written.', 'aria-labelledby': 'popp-next-commitment-title', 'aria-describedby': 'popp-next-commitment-description', onChange: function (event) { edit.change(function (next) { next.data.weeks[index].nextCommitment = event.target.value; }); } })
      ),
      !plan.readOnly ? e('div', { className: 'popp-bottom-actions' }, e(Button, { variant: 'quiet', disabled: !edit.dirty || edit.saving || !edit.editable, onClick: function () { edit.save(false); } }, 'Save draft'), e(Button, { disabled: edit.saving || !edit.editable, onClick: function () { edit.save(true); } }, edit.saving ? 'Saving…' : 'Submit Week ' + (index + 1))) : null
      )
    );
  }

  function ScoreTrend(props) {
    var data = props.data, width = 720, height = 270, padding = { top: 26, right: 28, bottom: 42, left: 46 };
    var plotWidth = width - padding.left - padding.right, plotHeight = height - padding.top - padding.bottom;
    var points = data.weeks.map(function (week, index) {
      return week.status === 'submitted' && hasNumber(week.score) ? { index: index, score: Number(week.score) } : null;
    }).filter(Boolean);
    var runningTotal = 0;
    var currentScorePoints = points.map(function (point, pointIndex) {
      runningTotal += point.score;
      return { index: point.index, score: Math.ceil(runningTotal / (pointIndex + 1)) };
    });
    var x = function (index) { return padding.left + (data.weeks.length > 1 ? index / (data.weeks.length - 1) * plotWidth : plotWidth / 2); };
    var y = function (score) { return padding.top + (100 - score) / 100 * plotHeight; };
    var pointList = points.map(function (point) { return x(point.index).toFixed(1) + ',' + y(point.score).toFixed(1); }).join(' ');
    var currentScorePointList = currentScorePoints.map(function (point) { return x(point.index).toFixed(1) + ',' + y(point.score).toFixed(1); }).join(' ');
    var ticks = [100, 80, 60, 40, 20, 0];
    var chartLabel = points.length ? 'Execution score trend. ' + points.map(function (point, pointIndex) { return 'Week ' + (point.index + 1) + ': weekly score ' + point.score + ' percent, current score ' + currentScorePoints[pointIndex].score + ' percent'; }).join('. ') + '.' : 'No submitted weekly scores yet.';
    var bestScore = points.length ? Math.max.apply(null, points.map(function (point) { return point.score; })) : null;
    return e('section', { className: 'popp-card popp-score-chart', 'aria-labelledby': 'popp-score-chart-title' },
      e('div', { className: 'popp-score-chart-header' }, e('div', null, e('h3', { id: 'popp-score-chart-title' }, 'Execution score trend'), e('p', null, 'Track weekly execution and your current score against the 80% target.')), points.length ? e('div', { className: 'popp-score-chart-summary' }, e('span', null, 'Best week'), e('strong', { className: 'popp-score-value--' + scoreTone(bestScore) }, bestScore + '%')) : null),
      points.length ? e('div', { className: 'popp-score-chart-canvas' }, e('svg', { viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': chartLabel },
        e('g', { className: 'popp-score-chart-grid' }, ticks.map(function (tick) { return e('g', { key: tick }, e('line', { x1: padding.left, x2: width - padding.right, y1: y(tick), y2: y(tick) }), e('text', { x: padding.left - 10, y: y(tick) + 4, textAnchor: 'end' }, tick + '%')); })),
        e('line', { className: 'popp-score-chart-target', x1: padding.left, x2: width - padding.right, y1: y(80), y2: y(80) }),
        e('text', { className: 'popp-score-chart-target-label', x: width - padding.right, y: y(80) - 7, textAnchor: 'end' }, '80% target'),
        e('polyline', { className: 'popp-score-chart-line', points: pointList }),
        e('polyline', { className: 'popp-score-chart-current-line', points: currentScorePointList }),
        points.map(function (point) { return e('g', { className: 'popp-score-chart-point popp-score-chart-point--' + scoreTone(point.score), key: point.index }, e('circle', { cx: x(point.index), cy: y(point.score), r: 6 }), e('title', null, 'Week ' + (point.index + 1) + ': ' + point.score + '%')); }),
        currentScorePoints.map(function (point) { return e('g', { className: 'popp-score-chart-current-point', key: point.index }, e('circle', { cx: x(point.index), cy: y(point.score), r: 4 }), e('title', null, 'Current score after Week ' + (point.index + 1) + ': ' + point.score + '%')); }),
        data.weeks.map(function (week, index) { return e('text', { className: 'popp-score-chart-x-label', key: index, x: x(index), y: height - 16, textAnchor: 'middle' }, 'W' + (index + 1)); })
      ), e('div', { className: 'popp-score-chart-legend', 'aria-label': 'Graph legend' }, e('span', { className: 'popp-score-chart-legend-weekly' }, 'Weekly score'), e('span', { className: 'popp-score-chart-legend-current' }, 'Current score'))) : e('div', { className: 'popp-score-chart-empty' }, 'Submit your first week to see your execution score trend.')
    );
  }

  function History(props) {
    var plan = props.plan;
    var submitted = plan.data.weeks.filter(function (week) { return week.status === 'submitted'; });
    var scoreToDate = submitted.length ? Math.ceil(submitted.reduce(function (total, week) { return total + week.score; }, 0) / submitted.length) : 0;
    return e('section', null, e(SectionHeader, { title: 'Score history', copy: 'Your submitted weekly execution scores.', aside: e('div', { className: 'popp-score-to-date' }, e('span', null, 'Score to date'), e('strong', null, scoreToDate + '%')) }), e('section', { className: 'popp-card popp-history' }, plan.data.weeks.map(function (week, index) { return e('div', { className: week.status === 'submitted' ? 'is-submitted' : '', key: index }, e('span', null, 'Week ' + (index + 1)), e('small', null, weekDate(plan.data.weekOneEnding, index)), e('strong', null, week.status === 'submitted' ? week.score + '%' : '—')); })), e(ScoreTrend, { data: plan.data }));
  }
  function SectionHeader(props) { return e('header', { className: 'popp-section-header' }, e('div', null, e('span', null, '12-Week Execution Score'), e('h2', null, props.title), e('p', null, props.copy)), props.aside || (props.edit && !props.edit.editable ? e('b', { className: 'popp-locked' }, 'Read only') : null)); }

  function Instructions() {
    var cards = [['1', 'Set up once', 'Use Week 0 to define 3–5 lead activities, weekly minimums, three Rocks, and your first commitment.'], ['2', 'Do the work', 'Execute the recurring lead activities and the one-time commitment during the week.'], ['3', 'Score the week', 'Record actuals. Meeting the minimum is a Hit; anything below it is a Miss. Each activity and both commitment criteria carry equal weight.'], ['4', 'Commit again', 'Write one executable commitment for the following week. It becomes that week’s “last week’s commitment.”']];
    return e('section', null, e(SectionHeader, { title: 'Sales Action Plan instructions', copy: 'How it works' }), e('div', { className: 'popp-instruction-grid' }, cards.map(function (card) { return e('article', { key: card[0] }, e('span', null, card[0]), e('div', null, e('h3', null, card[1]), e('p', null, card[2]))); })), e('article', { className: 'popp-card popp-instructions-note' }, e('h3', null, 'Keeping score'), e('p', null, 'Your weekly score is the percentage of successful criteria, rounded up to the next whole number. The target is 100%; 80% is the execution threshold. Scores are calculated and verified by the server when a week is submitted.'), e('h3', null, 'Weekly cadence'), e('p', null, 'Review progress toward your Rocks, report the last execution score, discuss hits and misses, and share the commitment for the upcoming week. A useful cadence session takes roughly 15–30 minutes.')));
  }

  function Team(props) {
    var queryState = useState(''), query = queryState[0], setQuery = queryState[1];
    if (props.team === null) return e('div', { className: 'popp-loading' }, 'Loading team progress…');
    var items = props.team.items || [], pagination = props.team.pagination;
    function search(event) { event.preventDefault(); props.loadTeam(1, query); }
    return e('section', null,
      e(SectionHeader, { title: 'Team progress', copy: 'Search students and select an action plan to view progress. All student plans are read only.' }),
      e('form', { className: 'popp-team-search', onSubmit: search }, e('label', null, e('span', { className: 'screen-reader-text' }, 'Search students'), e('input', { value: query, placeholder: 'Search students by name', onChange: function (event) { setQuery(event.target.value); } })), e(Button, { type: 'submit', variant: 'quiet' }, 'Search')),
      items.length ? e('div', { className: 'popp-team-list' }, items.map(function (member) { return e('article', { className: 'popp-card', key: member.id }, e('h3', null, member.name), member.plans.length ? member.plans.map(function (plan) { return e('div', { className: 'popp-team-plan', key: plan.id }, e('div', null, e('b', null, plan.title), e(Status, { status: plan.status })), e(Button, { variant: 'quiet', onClick: function () { props.openPlan(plan.id); } }, 'View progress')); }) : e('p', { className: 'popp-muted' }, 'No action plans yet.')); })) : e('section', { className: 'popp-card' }, e('h3', null, 'No students found'), e('p', null, 'No accessible students match this search.')),
      e('nav', { className: 'popp-pagination', 'aria-label': 'Team progress pages' }, e(Button, { variant: 'quiet', disabled: pagination.page <= 1, onClick: function () { props.loadTeam(pagination.page - 1, query); } }, 'Previous'), e('span', null, 'Page ' + pagination.page + ' of ' + pagination.totalPages + ' · ' + pagination.total + ' students'), e(Button, { variant: 'quiet', disabled: pagination.page >= pagination.totalPages, onClick: function () { props.loadTeam(pagination.page + 1, query); } }, 'Next'))
    );
  }

  window.wp.element.render(e(App), root);
}());
