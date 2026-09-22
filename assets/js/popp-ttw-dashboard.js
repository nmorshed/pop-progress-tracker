(function (wp) {
  'use strict';

  var root = document.querySelector('[data-popp-ttw-dashboard]');
  if (!root || !window.POPP_TTW_DASHBOARD || !wp || !wp.element) return;

  var e = wp.element.createElement;
  var useEffect = wp.element.useEffect;
  var useRef = wp.element.useRef;
  var useState = wp.element.useState;
  var config = window.POPP_TTW_DASHBOARD;

  function api(path, options) {
    options = options || {};
    return fetch(config.root + path, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Cache-Control': 'no-store',
        'X-WP-Nonce': config.nonce
      },
      body: options.body ? JSON.stringify(options.body) : undefined
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (json) {
        if (!response.ok) {
          var error = new Error(json.message || 'Something went wrong. Please try again.');
          error.status = response.status;
          throw error;
        }
        return json;
      });
    });
  }

  function clone(value) { return JSON.parse(JSON.stringify(value)); }
  function uid(prefix) { return prefix + '-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8); }
  function message(error) { return error && error.message ? error.message : 'Something went wrong. Please try again.'; }
  function present(value) { return value !== '' && value !== null && value !== undefined && !isNaN(parseFloat(value)); }
  function percent(value) { return value === null || value === '' || value === undefined ? '—' : Math.round(value) + '%'; }
  function tone(value, target) { return value === null || value === '' || value === undefined ? '' : Number(value) >= target ? ' is-good' : ' is-bad'; }

  function computeExecution(data) {
    return data.weeks.map(function (_, weekIndex) {
      var total = 0;
      var met = 0;
      data.metrics.forEach(function (metric) {
        if (!present(metric.goal) || !present(metric.values[weekIndex])) return;
        total++;
        if (Number(metric.values[weekIndex]) >= Number(metric.goal)) met++;
      });
      return total ? Math.round((met / total) * 100) : null;
    });
  }

  function repHasData(rep) {
    return rep.tactics.some(present) || rep.kept !== '' || String(rep.newCommitment || '').trim() !== '';
  }

  function computeWeeklyScore(rep) {
    if (!repHasData(rep)) return null;
    var points = rep.tactics.reduce(function (total, value) { return total + (present(value) && Number(value) >= 100 ? 1 : 0); }, 0);
    if (rep.kept === 'yes') points++;
    if (String(rep.newCommitment || '').trim() !== '') points++;
    return Math.round((points / 5) * 100);
  }

  function computeTeamScore(data) {
    var scores = data.reps.filter(function (rep) { return String(rep.name || '').trim() !== ''; }).map(computeWeeklyScore).filter(function (score) { return score !== null; });
    return scores.length ? Math.round(scores.reduce(function (sum, score) { return sum + score; }, 0) / scores.length) : null;
  }

  function computeKept(data) {
    var answered = data.reps.filter(function (rep) { return rep.kept === 'yes' || rep.kept === 'no'; });
    if (!answered.length) return null;
    return Math.round((answered.filter(function (rep) { return rep.kept === 'yes'; }).length / answered.length) * 100);
  }

  function computeCommitments(data) {
    var roster = data.reps.filter(function (rep) { return String(rep.name || '').trim() !== ''; });
    if (!roster.length) return null;
    return Math.round((roster.filter(function (rep) { return String(rep.newCommitment || '').trim() !== ''; }).length / roster.length) * 100);
  }

  function computeTacticAverage(data, index) {
    var values = data.reps.map(function (rep) { return rep.tactics[index]; }).filter(present).map(Number);
    return values.length ? Math.round(values.reduce(function (sum, value) { return sum + value; }, 0) / values.length) : null;
  }

  function csvCell(value) {
    var text = value === null || value === undefined ? '' : String(value);
    return '"' + text.replace(/"/g, '""') + '"';
  }

  function downloadCsv(filename, rows) {
    var content = '\uFEFF' + rows.map(function (row) { return row.map(csvCell).join(','); }).join('\r\n');
    var blob = new Blob([content], { type: 'text/csv;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  function fileDate() { return new Date().toISOString().slice(0, 10); }

  function nextWeekLabel(label) {
    var week = String(label || '').match(/^Week\s+(\d+)$/i);
    if (week) return 'Week ' + (Number(week[1]) + 1);
    var date = new Date(label);
    if (!isNaN(date.getTime())) {
      date.setDate(date.getDate() + 7);
      return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }
    return 'New Week';
  }

  function Button(props) {
    return e('button', { type: props.type || 'button', className: 'popttw-button' + (props.variant ? ' popttw-button--' + props.variant : ''), disabled: !!props.disabled, onClick: props.onClick }, props.children);
  }

  function App() {
    var workspaceState = useState(null), workspace = workspaceState[0], setWorkspace = workspaceState[1];
    var tabState = useState('ttw'), tab = tabState[0], setTab = tabState[1];
    var loadingState = useState(true), loading = loadingState[0], setLoading = loadingState[1];
    var errorState = useState(null), error = errorState[0], setError = errorState[1];
    var statusState = useState({ ttw: 'saved', scorecard: 'saved' }), status = statusState[0], setStatus = statusState[1];
    var workspaceRef = useRef(null);
    var queues = useRef({
      ttw: { timer: null, saving: false, data: null },
      scorecard: { timer: null, saving: false, data: null }
    });

    useEffect(function () {
      api('ttw-dashboard').then(function (data) {
        workspaceRef.current = data;
        setWorkspace(data);
      }).catch(function (err) {
        setError({ message: message(err), status: err.status || 0, section: 'load' });
      }).finally(function () {
        setLoading(false);
      });
      return function () {
        ['ttw', 'scorecard'].forEach(function (section) {
          if (queues.current[section].timer) window.clearTimeout(queues.current[section].timer);
        });
      };
    }, []);

    useEffect(function () { workspaceRef.current = workspace; }, [workspace]);

    useEffect(function () {
      function warn(event) {
        var pending = ['ttw', 'scorecard'].some(function (section) {
          var queue = queues.current[section];
          return queue.saving || queue.data || status[section] === 'dirty';
        });
        if (!pending) return;
        event.preventDefault();
        event.returnValue = '';
      }
      window.addEventListener('beforeunload', warn);
      return function () { window.removeEventListener('beforeunload', warn); };
    }, [status]);

    function setSectionStatus(section, value) {
      setStatus(function (current) { var next = Object.assign({}, current); next[section] = value; return next; });
    }

    function flush(section) {
      var queue = queues.current[section];
      if (queue.saving || !queue.data || !workspaceRef.current) return;
      var data = queue.data;
      var failed = false;
      queue.data = null;
      queue.saving = true;
      setSectionStatus(section, 'saving');
      var revisionKey = section === 'ttw' ? 'ttwRevision' : 'scorecardRevision';
      var path = section === 'ttw' ? 'ttw-dashboard/trailing-weeks' : 'ttw-dashboard/team-scorecard';
      api(path, { method: 'PUT', body: { revision: workspaceRef.current[revisionKey], data: data } }).then(function (saved) {
        setWorkspace(function (current) {
          var next = Object.assign({}, current);
          next[revisionKey] = saved[revisionKey];
          return next;
        });
        if (workspaceRef.current) workspaceRef.current[revisionKey] = saved[revisionKey];
        setError(function (current) { return current && current.section === section ? null : current; });
      }).catch(function (err) {
        failed = true;
        if (!queue.data) queue.data = data;
        setError({ message: message(err), status: err.status || 0, section: section });
        setSectionStatus(section, 'error');
      }).finally(function () {
        queue.saving = false;
        if (queue.data && !failed) {
          queue.timer = window.setTimeout(function () { flush(section); }, 0);
        } else if (!queue.data) {
          setSectionStatus(section, 'saved');
        }
      });
    }

    function change(section, nextData, immediate) {
      setWorkspace(function (current) { var next = Object.assign({}, current); next[section] = nextData; return next; });
      var queue = queues.current[section];
      queue.data = clone(nextData);
      if (queue.timer) window.clearTimeout(queue.timer);
      setSectionStatus(section, 'dirty');
      queue.timer = window.setTimeout(function () { flush(section); }, immediate ? 0 : 700);
    }

    function retry(section) {
      setError(null);
      setSectionStatus(section, 'dirty');
      flush(section);
    }

    if (loading) return e('div', { className: 'popttw-loading', role: 'status' }, 'Loading your trailing twelve week dashboard…');
    if (!workspace) return e('div', { className: 'popttw-error', role: 'alert' }, error ? error.message : 'The dashboard could not be loaded.');

    return e('div', { className: 'popttw-app' },
      e('nav', { className: 'popttw-tabs', role: 'tablist', 'aria-label': 'Sales dashboards' },
        e('button', { id: 'popttw-tab-ttw', type: 'button', role: 'tab', 'aria-controls': 'popttw-panel-ttw', 'aria-selected': tab === 'ttw', className: tab === 'ttw' ? 'is-active' : '', onClick: function () { setTab('ttw'); } }, 'Trailing Twelve Week Dashboard'),
        e('button', { id: 'popttw-tab-scorecard', type: 'button', role: 'tab', 'aria-controls': 'popttw-panel-scorecard', 'aria-selected': tab === 'scorecard', className: tab === 'scorecard' ? 'is-active' : '', onClick: function () { setTab('scorecard'); } }, 'Team Execution Scorecard')
      ),
      error ? e('div', { className: 'popttw-notice popttw-notice--error', role: 'alert' }, e('span', null, error.message), error.section === tab && status[tab] === 'error' ? e(Button, { variant: 'quiet', onClick: error.status === 409 ? function () { window.location.reload(); } : function () { retry(tab); } }, error.status === 409 ? 'Reload dashboard' : 'Retry save') : null) : null,
      tab === 'ttw' ? e(TTWView, { data: workspace.ttw, status: status.ttw, change: function (data, immediate) { change('ttw', data, immediate); } }) : e(ScorecardView, { data: workspace.scorecard, status: status.scorecard, change: function (data, immediate) { change('scorecard', data, immediate); } })
    );
  }

  function SaveStatus(props) {
    var labels = { saved: 'All changes saved', dirty: 'Unsaved changes', saving: 'Saving…', error: 'Save failed' };
    return e('span', { className: 'popttw-save popttw-save--' + props.value, role: 'status' }, labels[props.value] || labels.saved);
  }

  function Hero(props) {
    return e('header', { className: 'popttw-hero' }, e('h1', null, props.title), e('p', null, props.copy));
  }

  function Toolbar(props) {
    return e('div', { className: 'popttw-toolbar' },
      e('div', { className: 'popttw-legend' }, e('span', null, e('i', { className: 'is-good' }), props.good), e('span', null, e('i', { className: 'is-bad' }), props.bad)),
      e('div', { className: 'popttw-actions' }, e('strong', null, 'Program on Persuasion®'), props.children)
    );
  }

  function TTWView(props) {
    var data = props.data;
    var execution = computeExecution(data);

    function update(mutator, immediate) { var next = clone(data); mutator(next); props.change(next, immediate); }
    function updateMetric(index, field, value) { update(function (next) { next.metrics[index][field] = value; }); }
    function updateValue(metricIndex, weekIndex, value) { update(function (next) { next.metrics[metricIndex].values[weekIndex] = value; }); }
    function addMetric() { update(function (next) { next.metrics.push({ id: uid('metric'), who: '', name: '', goal: '', values: next.weeks.map(function () { return ''; }) }); }, true); }
    function removeMetric(index) { if (data.metrics.length <= 1) return; update(function (next) { next.metrics.splice(index, 1); }, true); }
    function addWeek() {
      update(function (next) {
        next.weeks.push(nextWeekLabel(next.weeks[next.weeks.length - 1]));
        next.weeks.shift();
        next.metrics.forEach(function (metric) { metric.values.push(''); metric.values.shift(); });
      }, true);
    }
    function exportCsv() {
      var rows = [['Who', 'Key Sales Metric', 'Goal'].concat(data.weeks)];
      data.metrics.forEach(function (metric) { rows.push([metric.who, metric.name, metric.goal].concat(metric.values)); });
      rows.push([data.executionWho, 'Execution Score (%)', '80'].concat(execution.map(function (value) { return value === null ? '' : value; })));
      downloadCsv('trailing-twelve-week-dashboard-' + fileDate() + '.csv', rows);
    }

    return e('section', { id: 'popttw-panel-ttw', className: 'popttw-view', role: 'tabpanel', 'aria-labelledby': 'popttw-tab-ttw' },
      e(Hero, { title: 'Trailing Twelve Week Dashboard', copy: 'Track key sales metrics weekly. Assign one owner per metric. Set a goal for each. Review every week in the Sales Huddle.' }),
      e(Toolbar, { good: 'Above goal', bad: 'Below goal' },
        e(SaveStatus, { value: props.status }),
        e(Button, { variant: 'quiet', onClick: addWeek }, '+ Add Week'),
        e(Button, { variant: 'quiet', onClick: exportCsv }, 'Export CSV'),
        e(Button, { onClick: function () { window.print(); } }, 'Print')
      ),
      e('div', { className: 'popttw-table-wrap' }, e('table', { className: 'popttw-table popttw-table--ttw' },
        e('thead', null, e('tr', null,
          e('th', { scope: 'col' }, 'Who'), e('th', { scope: 'col' }, 'Key Sales Metric'), e('th', { scope: 'col' }, 'Goal'),
          data.weeks.map(function (week, index) { return e('th', { scope: 'col', key: index }, e('input', { value: week, maxLength: 40, 'aria-label': 'Week ' + (index + 1) + ' label', onChange: function (event) { var value = event.target.value; update(function (next) { next.weeks[index] = value; }); } })); }),
          e('th', { scope: 'col', className: 'popttw-remove-cell', 'aria-label': 'Row actions' })
        )),
        e('tbody', null, data.metrics.map(function (metric, metricIndex) {
          return e('tr', { key: metric.id },
            e('td', null, e('input', { value: metric.who, maxLength: 100, placeholder: 'Full name', 'aria-label': 'Metric owner', onChange: function (event) { updateMetric(metricIndex, 'who', event.target.value); } })),
            e('td', null, e('input', { value: metric.name, maxLength: 190, placeholder: 'Insert Key Sales Metric Here', 'aria-label': 'Key sales metric', onChange: function (event) { updateMetric(metricIndex, 'name', event.target.value); } })),
            e('td', null, e('input', { type: 'number', min: '0', step: 'any', value: metric.goal, placeholder: 'Goal', 'aria-label': 'Metric goal', onChange: function (event) { updateMetric(metricIndex, 'goal', event.target.value); } })),
            metric.values.map(function (value, weekIndex) { var reported = present(value) && present(metric.goal); return e('td', { className: reported ? tone(Number(value), Number(metric.goal)) : '', key: weekIndex }, e('input', { type: 'number', min: '0', step: 'any', value: value, 'aria-label': metric.name ? metric.name + ', ' + data.weeks[weekIndex] : 'Metric ' + (metricIndex + 1) + ', ' + data.weeks[weekIndex], onChange: function (event) { updateValue(metricIndex, weekIndex, event.target.value); } })); }),
            e('td', { className: 'popttw-remove-cell' }, e('button', { type: 'button', disabled: data.metrics.length <= 1, 'aria-label': 'Remove metric row', onClick: function () { removeMetric(metricIndex); } }, '×'))
          );
        })),
        e('tfoot', null,
          e('tr', { className: 'popttw-add-row' }, e('td', { colSpan: 16 }, e(Button, { variant: 'ghost', disabled: data.metrics.length >= 50, onClick: addMetric }, '+ Add Metric'))),
          e('tr', { className: 'popttw-score-row' },
            e('td', null, e('input', { value: data.executionWho, maxLength: 100, placeholder: 'Full name', 'aria-label': 'Execution score owner', onChange: function (event) { var value = event.target.value; update(function (next) { next.executionWho = value; }); } })),
            e('td', null, 'Execution Score (%)'), e('td', null, '80'),
            execution.map(function (value, index) { return e('td', { className: tone(value, 80), key: index }, percent(value)); }), e('td')
          )
        )
      )),
      e(Instructions, { type: 'ttw' })
    );
  }

  function ScorecardView(props) {
    var data = props.data;
    var teamScore = computeTeamScore(data);
    var kept = computeKept(data);
    var commitments = computeCommitments(data);
    var averages = [0, 1, 2].map(function (index) { return computeTacticAverage(data, index); });
    var reportedAverages = averages.filter(function (value) { return value !== null; });
    var tacticSummary = reportedAverages.length ? Math.round(reportedAverages.reduce(function (sum, value) { return sum + value; }, 0) / reportedAverages.length) : null;

    function update(mutator, immediate) { var next = clone(data); mutator(next); props.change(next, immediate); }
    function updateRep(index, field, value) { update(function (next) { next.reps[index][field] = value; }); }
    function addRep() { update(function (next) { next.reps.push({ id: uid('rep'), name: '', tactics: ['', '', ''], kept: '', newCommitment: '', rock: '', notes: '' }); }, true); }
    function removeRep(index) { if (data.reps.length <= 1) return; update(function (next) { next.reps.splice(index, 1); }, true); }
    function exportCsv() {
      var rows = [['Salesperson'].concat(data.tactics, ['Kept Last Commitment', 'New Commitment', 'Weekly Score (%)', 'Rock Update', 'Notes'])];
      data.reps.forEach(function (rep) { var score = computeWeeklyScore(rep); rows.push([rep.name].concat(rep.tactics, [rep.kept, rep.newCommitment, score === null ? '' : score, rep.rock, rep.notes])); });
      rows.push(['Team Execution Score', '', '', '', '', '', teamScore === null ? '' : teamScore, '', '']);
      downloadCsv('team-execution-scorecard-' + fileDate() + '.csv', rows);
    }

    return e('section', { id: 'popttw-panel-scorecard', className: 'popttw-view', role: 'tabpanel', 'aria-labelledby': 'popttw-tab-scorecard' },
      e(Hero, { title: 'Team Execution Scorecard', copy: "Track each rep's weekly execution — recurring tactics, commitments kept, and new commitments made. Review every week in the Sales Huddle." }),
      e('div', { className: 'popttw-snapshot' },
        e(Snapshot, { label: 'Team Execution Score', value: teamScore }),
        e(Snapshot, { label: 'Kept Last Commitment', value: kept }),
        e(Snapshot, { label: 'New Commitment Made', value: commitments }),
        e('article', { className: 'popttw-snapshot-card' + tone(tacticSummary, 80) }, e('span', null, 'Recurring Tactics'), e('strong', null, averages.every(function (value) { return value === null; }) ? '—' : ''), e('p', null, data.tactics.map(function (label, index) { return e('span', { key: index }, label + ': ' + percent(averages[index])); })))
      ),
      e(Toolbar, { good: 'Meeting cadence (80%+)', bad: 'Below cadence goal' },
        e(SaveStatus, { value: props.status }), e(Button, { variant: 'quiet', onClick: exportCsv }, 'Export CSV'), e(Button, { onClick: function () { window.print(); } }, 'Print')
      ),
      e('div', { className: 'popttw-table-wrap' }, e('table', { className: 'popttw-table popttw-table--scorecard' },
        e('thead', null, e('tr', null,
          e('th', { scope: 'col' }, 'Salesperson'),
          data.tactics.map(function (tactic, index) { return e('th', { scope: 'col', key: index }, e('input', { value: tactic, maxLength: 100, 'aria-label': 'Tactic ' + (index + 1) + ' label', onChange: function (event) { var value = event.target.value; update(function (next) { next.tactics[index] = value; }); } })); }),
          e('th', { scope: 'col' }, 'Kept Last Commitment'), e('th', { scope: 'col' }, 'New Commitment'), e('th', { scope: 'col' }, 'Weekly Score'), e('th', { scope: 'col' }, 'Rock Update'), e('th', { scope: 'col' }, 'Notes'), e('th', { scope: 'col', className: 'popttw-remove-cell', 'aria-label': 'Row actions' })
        )),
        e('tbody', null, data.reps.map(function (rep, repIndex) { var score = computeWeeklyScore(rep); return e('tr', { key: rep.id },
          e('td', null, e('input', { value: rep.name, maxLength: 100, placeholder: 'Full name', 'aria-label': 'Salesperson name', onChange: function (event) { updateRep(repIndex, 'name', event.target.value); } })),
          rep.tactics.map(function (value, tacticIndex) { return e('td', { className: present(value) ? tone(Number(value), 100) : '', key: tacticIndex }, e('div', { className: 'popttw-percent-input' }, e('input', { type: 'number', min: '0', step: 'any', value: value, 'aria-label': (rep.name || 'Salesperson ' + (repIndex + 1)) + ', ' + data.tactics[tacticIndex], onChange: function (event) { var nextValue = event.target.value; update(function (next) { next.reps[repIndex].tactics[tacticIndex] = nextValue; }); } }), e('span', null, '%'))); }),
          e('td', { className: rep.kept ? (rep.kept === 'yes' ? 'is-good' : 'is-bad') : '' }, e('select', { value: rep.kept, 'aria-label': (rep.name || 'Salesperson ' + (repIndex + 1)) + ' kept last commitment', onChange: function (event) { updateRep(repIndex, 'kept', event.target.value); } }, e('option', { value: '' }, '—'), e('option', { value: 'yes' }, 'Yes'), e('option', { value: 'no' }, 'No'))),
          e('td', null, e('input', { value: rep.newCommitment, maxLength: 500, placeholder: "This week's commitment", 'aria-label': 'New commitment', onChange: function (event) { updateRep(repIndex, 'newCommitment', event.target.value); } })),
          e('td', { className: 'popttw-computed' + tone(score, 80) }, percent(score)),
          e('td', null, e('input', { value: rep.rock, maxLength: 500, placeholder: 'Rock progress', 'aria-label': 'Rock update', onChange: function (event) { updateRep(repIndex, 'rock', event.target.value); } })),
          e('td', null, e('input', { value: rep.notes, maxLength: 1000, placeholder: 'Notes', 'aria-label': 'Notes', onChange: function (event) { updateRep(repIndex, 'notes', event.target.value); } })),
          e('td', { className: 'popttw-remove-cell' }, e('button', { type: 'button', disabled: data.reps.length <= 1, 'aria-label': 'Remove salesperson row', onClick: function () { removeRep(repIndex); } }, '×'))
        ); })),
        e('tfoot', null,
          e('tr', { className: 'popttw-add-row' }, e('td', { colSpan: 10 }, e(Button, { variant: 'ghost', disabled: data.reps.length >= 50, onClick: addRep }, '+ Add Salesperson'))),
          e('tr', { className: 'popttw-score-row' }, e('td', { colSpan: 6 }, 'Team Execution Score'), e('td', { className: tone(teamScore, 80) }, percent(teamScore)), e('td', { colSpan: 3 }))
        )
      )),
      e(Instructions, { type: 'scorecard' })
    );
  }

  function Snapshot(props) {
    return e('article', { className: 'popttw-snapshot-card' + tone(props.value, 80) }, e('span', null, props.label), e('strong', null, percent(props.value)));
  }

  function Instructions(props) {
    var ttw = [
      ['How to update', "Each Monday, enter this week's actual results in the current week's column. Add a week and the oldest week drops off automatically."],
      ['Goal column', "Enter your weekly goal or target for each metric. Cells turn green when a result is at or above goal, and orange when it's below."],
      ['Ownership', 'The Who column is the person responsible for driving the results — not necessarily the person pulling the data. One name per metric.'],
      ['Trend analysis', 'The purpose of 12 weeks of history is to see trends before they register as unfavorable. A declining trend should trigger action before it dips below goal.'],
      ['Execution score', 'The bottom row calculates itself — the share of reported metrics that hit their goal that week. Green is 80% or better, orange is below.']
    ];
    var scorecard = [
      ["How it's scored", "Each rep's Weekly Score is 5 items worth 20% each: the 3 recurring tactics, keeping last week's commitment, and making a new commitment this week."],
      ['Recurring tactics', "Rename the 3 tactic columns for your team. Enter each rep's attainment as a percent of target — 100% or more earns the point and turns green."],
      ['Kept last commitment', "Did this rep follow through on what they committed to in last week's huddle? Yes earns the point; No or blank does not."],
      ['New commitment', 'Entering what the rep is committing to for the coming week earns the point. An empty field means no commitment was made.'],
      ['Team Execution Score', "The bottom row averages every named rep's Weekly Score once they begin reporting. The target is 80% or better."],
      ['Independent from the TTW Dashboard', 'This tab is its own tool. Nothing entered here affects the Trailing Twelve Week Dashboard, and nothing there affects this.']
    ];
    var items = props.type === 'ttw' ? ttw : scorecard;
    var examples = ['Average Job Size', 'Gross Profit Margin (%)', 'Close Rate (%)', 'Calls on Key Accounts', 'Calls on Top 20 Target List', 'Sales Meetings / Presentations', 'Proposals Submitted', 'New Leads Generated', 'Appointments Secured', 'Backlog Value ($)', 'Pipeline Value ($)', 'Sales Made ($)', 'Billings ($)', 'YTD Revenue (% to plan)'];
    return e('section', { className: 'popttw-instructions' }, e('h2', null, 'Notes & Instructions'), e('div', { className: 'popttw-instruction-grid' }, items.map(function (item, index) { return e('article', { key: item[0] }, e('header', null, e('h3', null, item[0]), e('span', null, String(index + 1).padStart(2, '0'))), e('p', null, item[1])); })), props.type === 'ttw' ? e('article', { className: 'popttw-examples' }, e('h3', null, 'Examples of Key Sales Metrics'), e('ul', null, examples.map(function (example) { return e('li', { key: example }, example); }))) : null, e('footer', null, 'Program on Persuasion® — ' + (props.type === 'ttw' ? 'Trailing Twelve Week Dashboard' : 'Team Execution Scorecard')));
  }

  wp.element.createRoot(root).render(e(App));
})(window.wp);
