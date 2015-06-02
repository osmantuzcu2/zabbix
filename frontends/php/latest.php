<?php
/*
** Zabbix
** Copyright (C) 2001-2015 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/hosts.inc.php';
require_once dirname(__FILE__).'/include/items.inc.php';

$page['title'] = _('Latest data');
$page['file'] = 'latest.php';
$page['type'] = detect_page_type(PAGE_TYPE_HTML);
$page['scripts'] = ['multiselect.js'];

if (PAGE_TYPE_HTML == $page['type']) {
	define('ZBX_PAGE_DO_REFRESH', 1);
}

require_once dirname(__FILE__).'/include/page_header.php';

//	VAR						TYPE	OPTIONAL	FLAGS	VALIDATION	EXCEPTION
$fields = [
	'groupids' =>			[T_ZBX_INT, O_OPT, P_SYS,	DB_ID,		null],
	'hostids' =>			[T_ZBX_INT, O_OPT, P_SYS,	DB_ID,		null],
	'fullscreen' =>			[T_ZBX_INT, O_OPT, P_SYS,	IN('0,1'),	null],
	'select' =>				[T_ZBX_STR, O_OPT, null,	null,		null],
	'show_without_data' =>	[T_ZBX_INT, O_OPT, null,	IN('0,1'),	null],
	'show_details' =>		[T_ZBX_INT, O_OPT, null,	IN('0,1'),	null],
	'application' =>		[T_ZBX_STR, O_OPT, null,	null,		null],
	'filter_rst' =>			[T_ZBX_STR, O_OPT, P_SYS,	null,		null],
	'filter_set' =>			[T_ZBX_STR, O_OPT, P_SYS,	null,		null],
	'filterState' =>		[T_ZBX_INT, O_OPT, P_ACT,	null,		null],
	'favobj' =>				[T_ZBX_STR, O_OPT, P_ACT,	null,		null],
	'toggle_ids' =>			[T_ZBX_STR, O_OPT, P_ACT,	null,		null],
	'toggle_open_state' =>	[T_ZBX_INT, O_OPT, P_ACT,	null,		null],
	// sort and sortorder
	'sort' =>				[T_ZBX_STR, O_OPT, P_SYS, IN('"host","lastclock","name"'),				null],
	'sortorder' =>			[T_ZBX_STR, O_OPT, P_SYS, IN('"'.ZBX_SORT_DOWN.'","'.ZBX_SORT_UP.'"'),	null]
];
check_fields($fields);

/*
 * Permissions
 */
if (getRequest('groupids') && !API::HostGroup()->isReadable(getRequest('groupids'))) {
	access_deny();
}
if (getRequest('hostids') && !API::Host()->isReadable(getRequest('hostids'))) {
	access_deny();
}

/*
 * Ajax
 */
if (hasRequest('filterState')) {
	CProfile::update('web.latest.filter.state', getRequest('filterState'), PROFILE_TYPE_INT);
}
if (hasRequest('favobj')) {
	if ($_REQUEST['favobj'] == 'toggle') {
		// $_REQUEST['toggle_ids'] can be single id or list of ids,
		// where id xxxx is application id and id 0_xxxx is 0_ + host id
		if (!is_array($_REQUEST['toggle_ids'])) {
			if ($_REQUEST['toggle_ids'][1] == '_') {
				$hostId = substr($_REQUEST['toggle_ids'], 2);
				CProfile::update('web.latest.toggle_other', $_REQUEST['toggle_open_state'], PROFILE_TYPE_INT, $hostId);
			}
			else {
				$applicationId = $_REQUEST['toggle_ids'];
				CProfile::update('web.latest.toggle', $_REQUEST['toggle_open_state'], PROFILE_TYPE_INT, $applicationId);
			}
		}
		else {
			foreach ($_REQUEST['toggle_ids'] as $toggleId) {
				if ($toggleId[1] == '_') {
					$hostId = substr($toggleId, 2);
					CProfile::update('web.latest.toggle_other', $_REQUEST['toggle_open_state'], PROFILE_TYPE_INT, $hostId);
				}
				else {
					$applicationId = $toggleId;
					CProfile::update('web.latest.toggle', $_REQUEST['toggle_open_state'], PROFILE_TYPE_INT, $applicationId);
				}
			}
		}
	}
}

if((PAGE_TYPE_JS == $page['type']) || (PAGE_TYPE_HTML_BLOCK == $page['type'])){
	require_once dirname(__FILE__).'/include/page_footer.php';
	exit;
}

require_once dirname(__FILE__).'/include/views/js/monitoring.latest.js.php';

/*
 * Filter
 */
if (hasRequest('filter_set')) {
	CProfile::update('web.latest.filter.select', getRequest('select', ''), PROFILE_TYPE_STR);
	CProfile::update('web.latest.filter.show_without_data', getRequest('show_without_data', 0), PROFILE_TYPE_INT);
	CProfile::update('web.latest.filter.show_details', getRequest('show_details', 0), PROFILE_TYPE_INT);
	CProfile::update('web.latest.filter.application', getRequest('application', ''), PROFILE_TYPE_STR);
	CProfile::updateArray('web.latest.filter.groupids', getRequest('groupids', []), PROFILE_TYPE_STR);
	CProfile::updateArray('web.latest.filter.hostids', getRequest('hostids', []), PROFILE_TYPE_STR);
}
elseif (hasRequest('filter_rst')) {
	DBStart();
	CProfile::delete('web.latest.filter.select');
	CProfile::delete('web.latest.filter.show_without_data');
	CProfile::delete('web.latest.filter.show_details');
	CProfile::delete('web.latest.filter.application');
	CProfile::deleteIdx('web.latest.filter.groupids');
	CProfile::deleteIdx('web.latest.filter.hostids');
	DBend();
}

$filter = [
	'select' => CProfile::get('web.latest.filter.select', ''),
	'showWithoutData' => CProfile::get('web.latest.filter.show_without_data', 1),
	'showDetails' => CProfile::get('web.latest.filter.show_details'),
	'application' => CProfile::get('web.latest.filter.application', ''),
	'groupids' => CProfile::getArray('web.latest.filter.groupids'),
	'hostids' => CProfile::getArray('web.latest.filter.hostids')
];

// we'll need to hide the host column if only one host is selected
$singleHostSelected = (count($filter['hostids']) == 1);

$sortField = getRequest('sort', CProfile::get('web.'.$page['file'].'.sort', 'name'));
$sortOrder = getRequest('sortorder', CProfile::get('web.'.$page['file'].'.sortorder', ZBX_SORT_UP));

CProfile::update('web.'.$page['file'].'.sort', $sortField, PROFILE_TYPE_STR);
CProfile::update('web.'.$page['file'].'.sortorder', $sortOrder, PROFILE_TYPE_STR);

$applications = $items = $hostScripts = [];

// we'll only display the values if the filter is set
$filterSet = ($filter['select'] !== '' || $filter['application'] !== '' || $filter['groupids'] || $filter['hostids']);
if ($filterSet) {
	$hosts = API::Host()->get([
		'output' => ['name', 'hostid', 'status'],
		'hostids' => $filter['hostids'],
		'groupids' => $filter['groupids'],
		'selectGraphs' => API_OUTPUT_COUNT,
		'with_monitored_items' => true,
		'preservekeys' => true
	]);
}
else {
	$hosts = [];
}

if ($hosts) {

	foreach ($hosts as &$host) {
		$host['item_cnt'] = 0;
	}
	unset($host);

	if (!$singleHostSelected) {
		$sortFields = ($sortField === 'host') ? [['field' => 'name', 'order' => $sortOrder]] : ['name'];
		CArrayHelper::sort($hosts, $sortFields);
	}

	$hostIds = array_keys($hosts);

	$applications = null;

	// if an application filter is set, fetch the applications and then use them to filter items
	if ($filter['application'] !== '') {
		$applications = API::Application()->get([
			'output' => API_OUTPUT_EXTEND,
			'hostids' => $hostIds,
			'search' => ['name' => $filter['application']],
			'preservekeys' => true
		]);
	}

	$items = API::Item()->get([
		'hostids' => array_keys($hosts),
		'output' => ['itemid', 'name', 'type', 'value_type', 'units', 'hostid', 'state', 'valuemapid', 'status',
			'error', 'trends', 'history', 'delay', 'key_', 'flags'],
		'selectApplications' => ['applicationid'],
		'selectItemDiscovery' => ['ts_delete'],
		'applicationids' => ($applications !== null) ? zbx_objectValues($applications, 'applicationid') : null,
		'webitems' => true,
		'filter' => [
			'status' => [ITEM_STATUS_ACTIVE]
		],
		'preservekeys' => true
	]);

	// if the applications haven't been loaded when filtering, load them based on the retrieved items to avoid
	// fetching applications from hosts that may not be displayed
	if ($applications === null) {
		$applications = API::Application()->get([
			'output' => API_OUTPUT_EXTEND,
			'hostids' => array_keys(array_flip(zbx_objectValues($items, 'hostid'))),
			'search' => ['name' => $filter['application']],
			'preservekeys' => true
		]);
	}
}

if ($items) {
	// macros
	$items = CMacrosResolverHelper::resolveItemKeys($items);
	$items = CMacrosResolverHelper::resolveItemNames($items);

	// filter items by name
	foreach ($items as $key => $item) {
		if (($filter['select'] !== '')) {
			$haystack = mb_strtolower($item['name_expanded']);
			$needle = mb_strtolower($filter['select']);

			if (mb_strpos($haystack, $needle) === false) {
				unset($items[$key]);
			}
		}
	}

	if ($items) {
		// get history
		$history = Manager::History()->getLast($items, 2, ZBX_HISTORY_PERIOD);

		// filter items without history
		if (!$filter['showWithoutData']) {
			foreach ($items as $key => $item) {
				if (!isset($history[$item['itemid']])) {
					unset($items[$key]);
				}
			}
		}
	}

	if ($items) {
		// add item last update date for sorting
		foreach ($items as &$item) {
			if (isset($history[$item['itemid']])) {
				$item['lastclock'] = $history[$item['itemid']][0]['clock'];
			}
		}
		unset($item);

		// sort
		if ($sortField === 'name') {
			$sortFields = [['field' => 'name_expanded', 'order' => $sortOrder], 'itemid'];
		}
		elseif ($sortField === 'lastclock') {
			$sortFields = [['field' => 'lastclock', 'order' => $sortOrder], 'name_expanded', 'itemid'];
		}
		else {
			$sortFields = ['name_expanded', 'itemid'];
		}
		CArrayHelper::sort($items, $sortFields);

		if ($applications) {
			foreach ($applications as &$application) {
				$application['hostname'] = $hosts[$application['hostid']]['name'];
				$application['item_cnt'] = 0;
			}
			unset($application);

			// by default order by application name and application id
			$sortFields = ($sortField === 'host') ? [['field' => 'hostname', 'order' => $sortOrder]] : [];
			array_push($sortFields, 'name', 'applicationid');
			CArrayHelper::sort($applications, $sortFields);
		}

		if (!$singleHostSelected) {
			// get host scripts
			$hostScripts = API::Script()->getScriptsByHosts($hostIds);

			// get templates screen count
			$screens = API::TemplateScreen()->get([
				'hostids' => $hostIds,
				'countOutput' => true,
				'groupCount' => true
			]);
			$screens = zbx_toHash($screens, 'hostid');
			foreach ($hosts as &$host) {
				$host['screens'] = isset($screens[$host['hostid']]);
			}
			unset($host);
		}
	}
}

// multiselect hosts
$multiSelectHostData = [];
if ($filter['hostids']) {
	$filterHosts = API::Host()->get([
		'output' => ['hostid', 'name'],
		'hostids' => $filter['hostids']
	]);

	foreach ($filterHosts as $host) {
		$multiSelectHostData[] = [
			'id' => $host['hostid'],
			'name' => $host['name']
		];
	}
}

// multiselect host groups
$multiSelectHostGroupData = [];
if ($filter['groupids'] !== null) {
	$filterGroups = API::HostGroup()->get([
		'output' => ['groupid', 'name'],
		'groupids' => $filter['groupids']
	]);

	foreach ($filterGroups as $group) {
		$multiSelectHostGroupData[] = [
			'id' => $group['groupid'],
			'name' => $group['name']
		];
	}
}

/*
 * Display
 */
$latestWidget = (new CWidget())->setTitle(_('Latest data'));

// Filter
$filterForm = new CFilter('web.latest.filter.state');

$filterColumn1 = new CFormList();
$filterColumn1->addRow(
	_('Host groups'),
	new CMultiSelect(
		[
			'name' => 'groupids[]',
			'objectName' => 'hostGroup',
			'data' => $multiSelectHostGroupData,
			'popup' => [
				'parameters' => 'srctbl=host_groups&dstfrm=zbx_filter&dstfld1=groupids_'.
					'&srcfld1=groupid&multiselect=1'
			]
	])
);
$filterColumn1->addRow(
		_('Hosts'),
		new CMultiSelect(
			[
				'name' => 'hostids[]',
				'objectName' => 'hosts',
				'data' => $multiSelectHostData,
				'popup' => [
					'parameters' => 'srctbl=hosts&dstfrm=zbx_filter&dstfld1=hostids_&srcfld1=hostid'.
						'&real_hosts=1&multiselect=1'
				]
			]
		)
);
$filterColumn1->addRow(
	_('Application'),
	[
		new CTextBox('application', $filter['application']),
		new CButton('application_name', _('Select'),
			'return PopUp("popup.php?srctbl=applications&srcfld1=name&real_hosts=1&dstfld1=application'.
				'&with_applications=1&dstfrm=zbx_filter");',
			'button-form'
		)
	]
);

$filterColumn2 = new CFormList();
$filterColumn2->addRow(
	_('Name'),
	new CTextBox('select', $filter['select'], 40)
);
$filterColumn2->addRow(
	_('Show items without data'),
	new CCheckBox('show_without_data', $filter['showWithoutData'], null, 1)
);
$filterColumn2->addRow(
	_('Show details'),
	new CCheckBox('show_details', $filter['showDetails'], null, 1)
);

$filterForm->addColumn($filterColumn1);
$filterForm->addColumn($filterColumn2);

$latestWidget->addItem($filterForm);
// End of Filter

$controls = new CList();
$controls->addItem(get_icon('fullscreen', ['fullscreen' => getRequest('fullscreen')]));
$latestWidget->setControls($controls);

$form = new CForm('GET', 'history.php');
$form->setName('items');
// set an ID for the hidden input so that it wouldn't conflict with the ID of the "Go" button list
$form->addItem(new CVar('action', HISTORY_BATCH_GRAPH, 'action-hidden'));

// table
$table = new CTableInfo();
if ($filterSet) {
	$table->setNoDataMessage(_('Specify some filter condition to see the values.'));
}

if ($singleHostSelected) {
	$hostHeader = null;
	$hostColumn = null;
}
else {
	$hostHeader = make_sorting_header(_('Host'), 'host', $sortField, $sortOrder);
	$hostHeader->addClass('latest-host '.($filter['showDetails'] ? 'with-details' : 'no-details'));
	$hostHeader->setAttribute('title', _('Host'));

	$hostColumn = '';
}

$nameHeader = make_sorting_header(_('Name'), 'name', $sortField, $sortOrder);
$nameHeader->setAttribute('title', _('Name'));

$lastCheckHeader = make_sorting_header(_('Last check'), 'lastclock', $sortField, $sortOrder);
$lastCheckHeader->addClass('latest-lastcheck');
$lastCheckHeader->setAttribute('title', _('Last check'));

$lastValueHeader = (new CColHeader(new CSpan(_('Last value'))))
	->addClass('latest-lastvalue')
	->setAttribute('title', _('Last value'));

$lastDataHeader = (new CColHeader(new CSpan(_x('Change', 'noun in latest data'))))
	->addClass('latest-data')
	->setAttribute('title', _x('Change', 'noun in latest data'));

$checkAllCheckbox = new CCheckBox('all_items', null, "checkAll('".$form->getName()."', 'all_items', 'itemids');");

$checkAllCheckboxCol = (new CColHeader($checkAllCheckbox))->addClass(ZBX_STYLE_CELL_WIDTH);

if ($filter['showDetails']) {
	$intervalHeader = (new CColHeader(_('Interval')))
		->addClass('latest-interval')
		->setAttribute('title', _('Interval'));

	$historyHeader = (new CColHeader(_('History')))
		->addClass('latest-history')
		->setAttribute('title', _('History'));

	$trendsHeader = (new CColHeader(_('Trends')))
		->addClass('latest-trends')
		->setAttribute('title', _('Trends'));

	$typeHeader = (new CColHeader(_('Type')))
		->addClass('latest-type')
		->setAttribute('title', _('Type'));

	$infoHeader = (new CColHeader(_('Info')))
		->addClass('latest-info')
		->setAttribute('title', _('Info'));

	$table->addClass('latest-details');
	$table->setHeader([
		(new CColHeader((new CDiv())->addClass('app-list-toggle-all icon-plus-9x9')))
			->addClass(ZBX_STYLE_CELL_WIDTH),
		$checkAllCheckboxCol,
		$hostHeader,
		$nameHeader,
		$intervalHeader,
		$historyHeader,
		$trendsHeader,
		$typeHeader,
		$lastCheckHeader,
		$lastValueHeader,
		$lastDataHeader,
		(new CColHeader())->addClass('latest-actions'),
		$infoHeader
	]);
}
else {
	$table->setHeader([
		(new CColHeader((new CDiv())->addClass('app-list-toggle-all icon-plus-9x9')))
			->addClass(ZBX_STYLE_CELL_WIDTH),
		$checkAllCheckboxCol,
		$hostHeader,
		$nameHeader,
		$lastCheckHeader,
		$lastValueHeader,
		$lastDataHeader,
		(new CColHeader())->addClass('latest-actions')
	]);
}

$tab_rows = [];

$config = select_config();

foreach ($items as $key => $item){
	if (!$item['applications']) {
		continue;
	}

	$lastHistory = isset($history[$item['itemid']][0]) ? $history[$item['itemid']][0] : null;
	$prevHistory = isset($history[$item['itemid']][1]) ? $history[$item['itemid']][1] : null;

	if (strpos($item['units'], ',') !== false) {
		list($item['units'], $item['unitsLong']) = explode(',', $item['units']);
	}
	else {
		$item['unitsLong'] = '';
	}

	// last check time and last value
	if ($lastHistory) {
		$lastClock = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $lastHistory['clock']);
		$lastValue = formatHistoryValue($lastHistory['value'], $item, false);
	}
	else {
		$lastClock = UNKNOWN_VALUE;
		$lastValue = UNKNOWN_VALUE;
	}

	// change
	$digits = ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT) ? 2 : 0;
	if ($lastHistory && $prevHistory
			&& ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64)
			&& (bcsub($lastHistory['value'], $prevHistory['value'], $digits) != 0)) {

		$change = '';
		if (($lastHistory['value'] - $prevHistory['value']) > 0) {
			$change = '+';
		}

		// for 'unixtime' change should be calculated as uptime
		$change .= convert_units([
			'value' => bcsub($lastHistory['value'], $prevHistory['value'], $digits),
			'units' => $item['units'] == 'unixtime' ? 'uptime' : $item['units']
		]);
		$change = nbsp($change);
	}
	else {
		$change = UNKNOWN_VALUE;
	}

	$showLink = ((($config['hk_history_global'] && $config['hk_history'] == 0) || $item['history'] == 0)
			&& (($config['hk_trends_global'] && $config['hk_trends'] == 0) || $item['trends'] == 0)
	);

	$checkbox = new CCheckBox('itemids['.$item['itemid'].']', null, null, $item['itemid']);
	$checkbox->removeAttribute('id');

	if ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64) {
		$actions = $showLink
			? UNKNOWN_VALUE
			: new CLink(_('Graph'), 'history.php?action='.HISTORY_GRAPH.'&itemids[]='.$item['itemid']);
	}
	else {
		$actions = $showLink
			? UNKNOWN_VALUE
			: new CLink(_('History'), 'history.php?action='.HISTORY_VALUES.'&itemids[]='.$item['itemid']);
		$checkbox->setEnabled(false);
	}

	$stateCss = ($item['state'] == ITEM_STATE_NOTSUPPORTED) ? ZBX_STYLE_GREY : '';

	if ($filter['showDetails']) {
		// item key
		$itemKey = ($item['type'] == ITEM_TYPE_HTTPTEST || $item['flags'] == ZBX_FLAG_DISCOVERY_CREATED)
			? (new CSpan($item['key_expanded']))->addClass(ZBX_STYLE_GREEN)
			: (new CLink($item['key_expanded'], 'items.php?form=update&itemid='.$item['itemid']))
				->addClass(ZBX_STYLE_LINK_ALT)
				->addClass(ZBX_STYLE_GREEN);

		// info
		if ($item['status'] == ITEM_STATUS_ACTIVE && $item['error'] !== '') {
			$info = (new CDiv())
				->addClass('status_icon iconerror')
				->setHint($item['error'], ZBX_STYLE_RED);
		}
		else {
			$info = '';
		}

		// trend value
		if ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64) {
			$trendValue = $config['hk_trends_global'] ? $config['hk_trends'] : $item['trends'];
		}
		else {
			$trendValue = UNKNOWN_VALUE;
		}

		$row = new CRow([
			'',
			$checkbox,
			$hostColumn,
			new CCol((new CDiv([$item['name_expanded'], BR(), $itemKey]))->addClass($stateCss.' item')),
			new CCol((new CSpan(
				($item['type'] == ITEM_TYPE_SNMPTRAP || $item['type'] == ITEM_TYPE_TRAPPER)
					? UNKNOWN_VALUE
					: $item['delay']))
					->addClass($stateCss)
			),
			new CCol((new CSpan($config['hk_history_global'] ? $config['hk_history'] : $item['history']))->addClass($stateCss)),
			new CCol((new CSpan($trendValue))->addClass($stateCss)),
			new CCol((new CSpan(item_type2str($item['type'])))->addClass($stateCss)),
			new CCol((new CSpan($lastClock))->addClass($stateCss)),
			new CCol((new CSpan($lastValue))->addClass($stateCss)),
			new CCol((new CSpan($change))->addClass($stateCss)),
			$actions,
			$info
		]);
	}
	else {
		$row = new CRow([
			'',
			$checkbox,
			$hostColumn,
			new CCol((new CSpan($item['name_expanded']))->addClass($stateCss)->addClass('item')),
			new CCol((new CSpan($lastClock))->addClass($stateCss)),
			new CCol((new CSpan($lastValue))->addClass($stateCss)),
			new CCol((new CSpan($change))->addClass($stateCss)),
			$actions
		]);
	}

	// add the item row to each application tab
	foreach ($item['applications'] as $itemApplication) {
		$applicationId = $itemApplication['applicationid'];

		if (isset($applications[$applicationId])) {
			$applications[$applicationId]['item_cnt']++;
			// objects may have different properties, so it's better to use a copy of it
			$tab_rows[$applicationId][] = clone $row;
		}
	}

	// remove items with applications from the collection
	unset($items[$key]);
}

foreach ($applications as $appid => $dbApp) {
	$host = $hosts[$dbApp['hostid']];

	if(!isset($tab_rows[$appid])) continue;

	$appRows = $tab_rows[$appid];

	$openState = CProfile::get('web.latest.toggle', null, $dbApp['applicationid']);

	$toggle = (new CDiv())
		->addClass('app-list-toggle icon-plus-9x9')
		->setAttribute('data-app-id', $dbApp['applicationid'])
		->setAttribute('data-open-state', $openState);
	if ($openState) {
		$toggle->addClass('icon-minus-9x9');
	}

	$hostName = null;

	if (!$singleHostSelected) {
		$hostName = (new CSpan($host['name']))
			->addClass(ZBX_STYLE_LINK_ACTION)
			->setMenuPopup(CMenuPopupHelper::getHost($host, $hostScripts[$host['hostid']]));
		if ($host['status'] == HOST_STATUS_NOT_MONITORED) {
			$hostName->addClass(ZBX_STYLE_RED);
		}
	}

	// add toggle row
	$table->addRow([
		$toggle,
		'',
		$hostName,
		new CCol([
				bold($dbApp['name']),
				' ('._n('%1$s Item', '%1$s Items', $dbApp['item_cnt']).')'
			], null, $filter['showDetails'] ? 10 : 5)
	], 'odd_row');

	// add toggle sub rows
	foreach ($appRows as $row) {
		$row->setAttribute('parent_app_id', $dbApp['applicationid']);
		$row->addClass('odd_row');
		if (!$openState) {
			$row->addClass('hidden');
		}
		$table->addRow($row);
	}
}

/**
 * Display OTHER ITEMS (which are not linked to application)
 */
$tab_rows = [];
foreach ($items as $item) {
	$lastHistory = isset($history[$item['itemid']][0]) ? $history[$item['itemid']][0] : null;
	$prevHistory = isset($history[$item['itemid']][1]) ? $history[$item['itemid']][1] : null;

	if (strpos($item['units'], ',') !== false)
		list($item['units'], $item['unitsLong']) = explode(',', $item['units']);
	else
		$item['unitsLong'] = '';

	// last check time and last value
	if ($lastHistory) {
		$lastClock = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $lastHistory['clock']);
		$lastValue = formatHistoryValue($lastHistory['value'], $item, false);
	}
	else {
		$lastClock = UNKNOWN_VALUE;
		$lastValue = UNKNOWN_VALUE;
	}

	// column "change"
	$digits = ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT) ? 2 : 0;
	if (isset($lastHistory['value']) && isset($prevHistory['value'])
			&& ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64)
			&& (bcsub($lastHistory['value'], $prevHistory['value'], $digits) != 0)) {

		$change = '';
		if (($lastHistory['value'] - $prevHistory['value']) > 0) {
			$change = '+';
		}

		// for 'unixtime' change should be calculated as uptime
		$change .= convert_units([
			'value' => bcsub($lastHistory['value'], $prevHistory['value'], $digits),
			'units' => $item['units'] == 'unixtime' ? 'uptime' : $item['units']
		]);
		$change = nbsp($change);
	}
	else {
		$change = UNKNOWN_VALUE;
	}

	// column "action"
	$showLink = ((($config['hk_history_global'] && $config['hk_history'] == 0) || $item['history'] == 0)
			&& (($config['hk_trends_global'] && $config['hk_trends'] == 0) || $item['trends'] == 0)
	);

	$checkbox = new CCheckBox('itemids['.$item['itemid'].']', null, null, $item['itemid']);
	$checkbox->removeAttribute('id');

	if ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64) {
		$actions = $showLink
			? UNKNOWN_VALUE
			: new CLink(_('Graph'), 'history.php?action='.HISTORY_GRAPH.'&itemids[]='.$item['itemid']);
	}
	else {
		$actions = $showLink
			? UNKNOWN_VALUE
			: new CLink(_('History'), 'history.php?action='.HISTORY_VALUES.'&itemids[]='.$item['itemid']);
		$checkbox->setEnabled(false);
	}

	$stateCss = ($item['state'] == ITEM_STATE_NOTSUPPORTED) ? ZBX_STYLE_GREY : '';

	$host = $hosts[$item['hostid']];
	if ($filter['showDetails']) {
		// item key
		$itemKey = ($item['type'] == ITEM_TYPE_HTTPTEST || $item['flags'] == ZBX_FLAG_DISCOVERY_CREATED)
			? (new CSpan($item['key_expanded']))->addClass('enabled')
			: (new CLink($item['key_expanded'], 'items.php?form=update&itemid='.$item['itemid']))
				->addClass(ZBX_STYLE_LINK_ALT)
				->addClass(ZBX_STYLE_GREEN);

		// info
		if ($item['status'] == ITEM_STATUS_ACTIVE && $item['error'] !== '') {
			$info = (new CDiv())
				->addClass('status_icon iconerror')
				->setHint($item['error'], ZBX_STYLE_RED);
		}
		else {
			$info = '';
		}

		// trend value
		if ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64) {
			$trendValue = $config['hk_trends_global'] ? $config['hk_trends'] : $item['trends'];
		}
		else {
			$trendValue = UNKNOWN_VALUE;
		}

		$row = new CRow([
			'',
			$checkbox,
			$hostColumn,
			new CCol((new CDiv([$item['name_expanded'], BR(), $itemKey]))->addClass($stateCss.' item')),
			new CCol((new CSpan(
				($item['type'] == ITEM_TYPE_SNMPTRAP || $item['type'] == ITEM_TYPE_TRAPPER)
					? UNKNOWN_VALUE
					: $item['delay']))
					->addClass($stateCss)
			),
			new CCol((new CSpan($config['hk_history_global'] ? $config['hk_history'] : $item['history']))->addClass($stateCss)),
			new CCol((new CSpan($trendValue))->addClass($stateCss)),
			new CCol((new CSpan(item_type2str($item['type'])))->addClass($stateCss)),
			new CCol((new CSpan($lastClock))->addClass($stateCss)),
			new CCol((new CSpan($lastValue))->addClass($stateCss)),
			new CCol((new CSpan($change))->addClass($stateCss)),
			(new CCol($actions))->addClass($stateCss),
			$info
		]);
	}
	else {
		$row = new CRow([
			'',
			$checkbox,
			$hostColumn,
			new CCol((new CSpan($item['name_expanded']))->addClass($stateCss)->addClass('item')),
			new CCol((new CSpan($lastClock))->addClass($stateCss)),
			new CCol((new CSpan($lastValue))->addClass($stateCss)),
			new CCol((new CSpan($change))->addClass($stateCss)),
			(new CCol($actions))->addClass($stateCss)
		]);
	}

	$hosts[$item['hostid']]['item_cnt']++;
	$tab_rows[$item['hostid']][] = $row;
}

foreach ($hosts as $hostId => $dbHost) {
	$host = $hosts[$dbHost['hostid']];

	if(!isset($tab_rows[$hostId])) {
		continue;
	}
	$appRows = $tab_rows[$hostId];

	$openState = CProfile::get('web.latest.toggle_other', null, $host['hostid']);

	$toggle = (new CDiv())
		->addClass('app-list-toggle icon-plus-9x9')
		->setAttribute('data-app-id', '0_'.$host['hostid'])
		->setAttribute('data-open-state', $openState);
	if ($openState) {
		$toggle->addClass('icon-minus-9x9');
	}

	$hostName = null;

	if (!$singleHostSelected) {
		$hostName = (new CSpan($host['name']))
			->addClass(ZBX_STYLE_LINK_ACTION)
			->setMenuPopup(CMenuPopupHelper::getHost($host, $hostScripts[$host['hostid']]));
		if ($host['status'] == HOST_STATUS_NOT_MONITORED) {
			$hostName->addClass(ZBX_STYLE_RED);
		}
	}

	// add toggle row
	$table->addRow([
		$toggle,
		'',
		$hostName,
		new CCol(
			[
				bold('- '.('other').' -'),
				' ('._n('%1$s Item', '%1$s Items', $dbHost['item_cnt']).')'
			],
			null, $filter['showDetails'] ? 10 : 5
		)
	], 'odd_row');

	// add toggle sub rows
	foreach($appRows as $row) {
		$row->setAttribute('parent_app_id', '0_'.$host['hostid']);
		$row->addClass('odd_row');
		if (!$openState) {
			$row->addClass('hidden');
		}
		$table->addRow($row);
	}
}

$form->addItem([
	$table,
	new CActionButtonList('graphtype', 'itemids', [
		GRAPH_TYPE_STACKED => ['name' => _('Display stacked graph')],
		GRAPH_TYPE_NORMAL => ['name' => _('Display graph')]
	])
]);

$latestWidget->addItem($form)->show();

require_once dirname(__FILE__).'/include/page_footer.php';
