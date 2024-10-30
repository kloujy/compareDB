<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// reference
$db1_host = 'localhost';
$db1_user = '';
$db1_pass = '';
$db1_name = '';

// destination
$db2_host = 'localhost';
$db2_user = '';
$db2_pass = '';
$db2_name = '';

// Create connections to both databases
$conn1 = new mysqli($db1_host, $db1_user, $db1_pass, $db1_name);
$conn2 = new mysqli($db2_host, $db2_user, $db2_pass, $db2_name);

// Check the connections
if ($conn1->connect_error) {
    die("Connection failed for $db1_user: " . $conn1->connect_error);
}
if ($conn2->connect_error) {
    die("Connection failed for $db2_user: " . $conn2->connect_error);
}

// Fetch tables from both databases
function getTables($conn) {
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    if ($result === false) {
        die("Error fetching tables: " . $conn->error);
    }
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    return $tables;
}

$tables1 = getTables($conn1);
$tables2 = getTables($conn2);

// Compare tables between the two databases
$common_tables = array_intersect($tables1, $tables2);

// Fetch table schema (columns and types)
function getTableSchema($conn, $table) {
    $schema = [];
    $result = $conn->query("DESCRIBE `$table`");
    while ($row = $result->fetch_assoc()) {
        $schema[] = ['Field' => $row['Field'], 'Type' => $row['Type'], 'Null' => $row['Null'], 'Default' => $row['Default']];
    }
    return $schema;
}

// Compare row counts
function compareRowCounts($conn1, $conn2, $tables) {
    $differences = [];
    foreach ($tables as $table) {
        $count1 = $conn1->query("SELECT COUNT(*) AS count FROM `$table`")->fetch_assoc()['count'];
        $count2 = $conn2->query("SELECT COUNT(*) AS count FROM `$table`")->fetch_assoc()['count'];

        if ($count1 != $count2) {
            $differences[] = "Row count mismatch in table $table: $db1_user has $count1 rows, $db2_user has $count2 rows.";
        }
    }
    return $differences;
}

// Fetch indexes
function getIndexes($conn, $table) {
    $indexes = [];
    $result = $conn->query("SHOW INDEX FROM `$table`");
    while ($row = $result->fetch_assoc()) {
        $indexes[] = [
            'Key_name' => $row['Key_name'],
            'Column_name' => $row['Column_name'],
            'Non_unique' => $row['Non_unique'] == 1 ? 'Non-Unique' : 'Unique',
            'Index_type' => $row['Index_type']
        ];
    }
    return $indexes;
}

// Fetch primary keys
function getPrimaryKey($conn, $table) {
    $primary_key = [];
    $result = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = 'PRIMARY'");
    while ($row = $result->fetch_assoc()) {
        $primary_key[] = $row['Column_name'];
    }
    return $primary_key;
}

// Fetch foreign keys
function getForeignKeys($conn, $table) {
    $foreign_keys = [];
    $result = $conn->query("
        SELECT
            kcu.COLUMN_NAME AS 'Column_Name',
            kcu.REFERENCED_TABLE_NAME AS 'Referenced_Table',
            kcu.REFERENCED_COLUMN_NAME AS 'Referenced_Column'
        FROM
            INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
        WHERE
            kcu.TABLE_NAME = '$table' AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    ");
    while ($row = $result->fetch_assoc()) {
        $foreign_keys[] = [
            'Column_Name' => $row['Column_Name'],
            'Referenced_Table' => $row['Referenced_Table'],
            'Referenced_Column' => $row['Referenced_Column']
        ];
    }
    return $foreign_keys;
}

// Compare schemas
$schema_differences = [];
foreach ($common_tables as $table) {
    $schema1 = getTableSchema($conn1, $table);
    $schema2 = getTableSchema($conn2, $table);

    // Check for schema differences
    foreach ($schema1 as $col1) {
        $found = false;
        foreach ($schema2 as $col2) {
            if ($col1['Field'] == $col2['Field']) {
                if ($col1['Type'] != $col2['Type']) {
                    $schema_differences[] = "Column <b class='success'>{$col1['Field']}</b> in table <b>$table</b> has different types: <span class='attention'>{$col1['Type']} <strong class='active'>||</strong> {$col2['Type']}</span>";
                }
                if($col1['Null'] != $col2['Null']){
                	$schema_differences[] = "Column <b class='success'>{$col1['Field']}</b> in table <b>$table</b> has different Null allowed: <span class='attention'>{$col1['Null']} <strong class='active'>||</strong> {$col2['Null']}</span>";	
                }
                if($col1['Default'] != $col2['Default']){
                	$schema_differences[] = "Column <b class='success'>{$col1['Field']}</b> in table <b>$table</b> has different Default value: <span class='attention'>".(($col1['Default'] != '') ? $col1['Default'] :'\'\'')." <strong class='active'>||</strong> ".(($col2['Default'] != '') ? $col2['Default'] :'\'\'')."</span>";	
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            $schema_differences[] = "Column <b class='success'>{$col1['Field']}</b> exists in <b>$table <strong>$db1_user</strong></b> but not in <b>$table <strong>$db2_user</strong></b>.";
        }
    }

    foreach ($schema2 as $col2) {
        $found = false;
        foreach ($schema1 as $col1) {
            if ($col1['Field'] == $col2['Field']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $schema_differences[] = "Column <b class='success'>{$col2['Field']}</b> exists in <b>$table <strong>$db2_user</strong></b> but not in <b>$table <strong>$db1_user</strong></b>.";
        }
    }
}

// Compare indexes
$index_differences = [];
foreach ($common_tables as $table) {
    $indexes1 = getIndexes($conn1, $table);
    $indexes2 = getIndexes($conn2, $table);
	$indexes_regroup1 = [];
	$indexes_regroup2 = [];
	$missmatch = [];
	
	foreach ($indexes1 as $index1){
		$indexes_regroup1[$index1['Key_name']][] = $index1;
	}
	foreach ($indexes2 as $index2){
		$indexes_regroup2[$index2['Key_name']][] = $index2;	
	}
	
	foreach ($indexes_regroup1 as $key=>$indexList){
		foreach($indexList as $index){
			$found = false;
			if(!empty($indexes_regroup2[$index['Key_name']])){
				$position = array_search($index['Column_name'], array_column($indexes_regroup2[$index['Key_name']], 'Column_name'));
				if(!empty($indexes_regroup2[$index['Key_name']][$position])){
					if($indexes_regroup2[$index['Key_name']][$position]['Non_unique'] == $index['Non_unique']){
						$found = true;
					}
				}
			}
			
			if(!$found && empty($index_differences[$table.'_'.$index['Key_name']])){
		    	ob_start();
			    echo "Index <b class='variant'>{$index['Key_name']}</b> mismatch in table <b>$table</b>.<br><strong>$db1_user</strong> Index: <pre>" . json_encode($indexes_regroup1[$index['Key_name']] ?? []) . "</pre><strong>$db2_user</strong> Index: <pre>" . json_encode($indexes_regroup2[$index['Key_name']] ?? [])."</pre>";
				$index_differences[$table.'_'.$index['Key_name']] = ob_get_clean();
			}
		}
	}
	
	foreach ($indexes_regroup2 as $key=>$indexList){
		foreach($indexList as $index){
			$found = false;
			if(!empty($indexes_regroup1[$index['Key_name']])){
				$position = array_search($index['Column_name'], array_column($indexes_regroup1[$index['Key_name']], 'Column_name'));
				if(!empty($indexes_regroup1[$index['Key_name']][$position])){
					if($indexes_regroup1[$index['Key_name']][$position]['Non_unique'] == $index['Non_unique']){
						$found = true;
					}
				}
			}
			
			if(!$found && empty($index_differences[$table.'_'.$index['Key_name']])){
		    	ob_start();
			    echo "Index <b class='variant'>{$index['Key_name']}</b> mismatch in table <b>$table</b>.<br><strong>$db1_user</strong> Index: <pre>" . json_encode($indexes_regroup1[$index['Key_name']] ?? []) . "</pre><strong>$db2_user</strong> Index: <pre>" . json_encode($indexes_regroup2[$index['Key_name']] ?? [])."</pre>";
				$index_differences[$table.'_'.$index['Key_name']] = ob_get_clean();
			}
		}
	}
}

// Compare primary keys
$pk_differences = [];
foreach ($common_tables as $table) {
    $pk1 = getPrimaryKey($conn1, $table);
    $pk2 = getPrimaryKey($conn2, $table);

    if ($pk1 != $pk2) {
        $pk_differences[] = "Primary key mismatch in table <b>$table</b>: <span class='attention'>" . implode(', ', $pk1) . " <strong class='active'>||</strong> " . implode(', ', $pk2)."</span>";
    }
}

// Compare foreign keys
$fk_differences = [];
foreach ($common_tables as $table) {
    $fk1 = getForeignKeys($conn1, $table);
    $fk2 = getForeignKeys($conn2, $table);

    if ($fk1 != $fk2) {
	    ob_start();
	    echo "Foreign key mismatch in table <b>$table</b>.<br><strong>$db1_user</strong> Foreign Keys: <pre>" . json_encode($fk1) . "</pre><strong>$db2_user</strong> Foreign Keys: <pre>" . json_encode($fk2)."</pre>";
        $fk_differences[] = ob_get_clean();
    }
}
?>
<!DOCTYPE html>
<html data-color-scheme="dark">
<head>
	<!--<link rel="stylesheet" href="https://matcha.mizu.sh/matcha.css">-->
	<style>:root{--ft:-apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans", Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji";--ft-mono:ui-monospace, SFMono-Regular, SF Mono, Menlo, Consolas, Liberation Mono, monospace;--ft-size:18px;--ly-header-size:3.5rem;--ly-aside-size-small:2.5rem;--ly-brand:#e6edf3;--ly-bg-brand:#010409;--light-default:#1f2328;--light-subtle:#1f2328;--light-contrast:#e6edf3;--light-muted:#656d76;--light-accent:#0969da;--light-active:#8250df;--light-variant:#bf3989;--light-success:#1a7f37;--light-attention:#9a6700;--light-severe:#bc4c00;--light-danger:#d1242f;--dark-default:#e6edf3;--dark-subtle:#e6edf3;--dark-contrast:#1f2328;--dark-muted:#848d97;--dark-accent:#4493f8;--dark-active:#a371f7;--dark-variant:#db61a2;--dark-success:#3fb950;--dark-attention:#d29922;--dark-severe:#db6d28;--dark-danger:#f85149;--light-bg-default:#ffffff;--light-bg-subtle:#d0d7de;--light-bg-contrast:#24292f;--light-bg-muted:#f6f8fa;--light-bg-accent:#ddf4ff;--light-bg-active:#fbefff;--light-bg-variant:#ffeff7;--light-bg-success:#dafbe1;--light-bg-attention:#fff8c5;--light-bg-severe:#fff1e5;--light-bg-danger:#ffebe9;--dark-bg-default:#0d1117;--dark-bg-subtle:#30363d;--dark-bg-contrast:#6e7681;--dark-bg-muted:#161b22;--dark-bg-accent:#121d2f;--dark-bg-active:#231f39;--dark-bg-variant:#221926;--dark-bg-success:#12261e;--dark-bg-attention:#272115;--dark-bg-severe:#221a19;--dark-bg-danger:#25171c;--light-backdrop:#8c959f33;--light-bd-muted:#d0d7deb3;--dark-bd-muted:#30363db3;--dark-backdrop:#161b2266;--bd-radius:6px;--tr-duration:.2s;--ct-width:1024px}:root,[data-color-scheme=light]{--default:var(--light-default);--subtle:var(--light-subtle);--contrast:var(--light-contrast);--muted:var(--light-muted);--accent:var(--light-accent);--active:var(--light-active);--variant:var(--light-variant);--success:var(--light-success);--attention:var(--light-attention);--severe:var(--light-severe);--danger:var(--light-danger);--bg-default:var(--light-bg-default);--bg-subtle:var(--light-bg-subtle);--bg-contrast:var(--light-bg-contrast);--bg-muted:var(--light-bg-muted);--bg-accent:var(--light-bg-accent);--bg-active:var(--light-bg-active);--bg-variant:var(--light-bg-variant);--bg-success:var(--light-bg-success);--bg-attention:var(--light-bg-attention);--bg-severe:var(--light-bg-severe);--bg-danger:var(--light-bg-danger);--bd-muted:var(--light-bd-muted);--backdrop:var(--light-backdrop)}[data-color-scheme=dark]{--default:var(--dark-default);--subtle:var(--dark-subtle);--contrast:var(--dark-contrast);--muted:var(--dark-muted);--accent:var(--dark-accent);--active:var(--dark-active);--variant:var(--dark-variant);--success:var(--dark-success);--attention:var(--dark-attention);--severe:var(--dark-severe);--danger:var(--dark-danger);--bg-default:var(--dark-bg-default);--bg-subtle:var(--dark-bg-subtle);--bg-contrast:var(--dark-bg-contrast);--bg-muted:var(--dark-bg-muted);--bg-accent:var(--dark-bg-accent);--bg-active:var(--dark-bg-active);--bg-variant:var(--dark-bg-variant);--bg-success:var(--dark-bg-success);--bg-attention:var(--dark-bg-attention);--bg-severe:var(--dark-bg-severe);--bg-danger:var(--dark-bg-danger);--bd-muted:var(--dark-bd-muted);--backdrop:var(--dark-backdrop)}@media (prefers-color-scheme:dark){:root:not([data-color-scheme=light]){--default:var(--dark-default);--subtle:var(--dark-subtle);--contrast:var(--dark-contrast);--muted:var(--dark-muted);--accent:var(--dark-accent);--active:var(--dark-active);--variant:var(--dark-variant);--success:var(--dark-success);--attention:var(--dark-attention);--severe:var(--dark-severe);--danger:var(--dark-danger);--bg-default:var(--dark-bg-default);--bg-subtle:var(--dark-bg-subtle);--bg-contrast:var(--dark-bg-contrast);--bg-muted:var(--dark-bg-muted);--bg-accent:var(--dark-bg-accent);--bg-active:var(--dark-bg-active);--bg-variant:var(--dark-bg-variant);--bg-success:var(--dark-bg-success);--bg-attention:var(--dark-bg-attention);--bg-severe:var(--dark-bg-severe);--bg-danger:var(--dark-bg-danger);--bd-muted:var(--dark-bd-muted);--backdrop:var(--dark-backdrop)}}:root{--shadow:0px 0px 0px 1px var(--bg-subtle), 0px 6px 12px -3px var(--backdrop), 0px 6px 18px 0px var(--backdrop);--shadow-r:6px 0px 18px 0px var(--backdrop);--shadow-l:-6px 0px 18px 0px var(--backdrop);--light:var(--dark-default);--dark:var(--light-default)}abbr,rp,rt{color:var(--muted)}abbr{text-decoration:underline dotted}abbr[data-title],abbr[title]{position:relative;color:var(--accent);cursor:help}abbr[data-title]::after{position:absolute;top:-125%;left:50%;display:none;padding:.5em;border:1px solid var(--bd-muted);border-radius:var(--bd-radius);background:var(--bg-muted);box-shadow:var(--shadow);color:var(--default);content:attr(data-title);font-size:.75em;opacity:0;pointer-events:none;transform:translateX(-50%);transition:opacity var(--tr-duration);white-space:nowrap}abbr[data-title]:hover::after,menu>li:hover>menu{display:block;opacity:1}a{color:var(--accent);text-decoration:inherit}a:hover,summary:hover{text-decoration:underline}rp,rt{font-size:75%}sub,sup{position:relative;font-size:75%;line-height:0;vertical-align:baseline}sup{top:-.5em}sub{bottom:-.25em}a:active:hover,mark{color:var(--active)}del,ins,mark{padding:0 .25rem;background:var(--bg-active)}ins{background:var(--bg-success);color:var(--success);text-decoration:underline}del{background:var(--bg-danger);color:var(--danger);text-decoration:line-through}meter,progress{overflow:hidden;width:100%;height:.5rem;border:transparent;border-radius:calc(.5*var(--bd-radius));margin:.5rem 0;appearance:none;background:var(--bg-subtle)}progress{vertical-align:baseline}progress::-webkit-progress-value{background-color:currentColor}progress::-moz-progress-bar{background-color:currentColor}meter::-webkit-meter-inner-element{position:relative;display:block}meter::-webkit-meter-bar,progress::-webkit-progress-bar{border:transparent;background:var(--bg-subtle)}meter::-webkit-meter-optimum-value{background:var(--success)}meter::-webkit-meter-suboptimum-value{background:var(--attention)}meter::-webkit-meter-even-less-good-value{background:var(--danger)}meter:-moz-meter-optimum::-moz-meter-bar{background:var(--success)}meter:-moz-meter-sub-optimum::-moz-meter-bar{background:var(--attention)}meter:-moz-meter-sub-sub-optimum::-moz-meter-bar{background:var(--danger)}details{display:block;padding:1rem;border:1px solid var(--bd-muted);border-radius:var(--bd-radius);margin:0 0 1rem}summary{display:list-item;border-radius:calc(var(--bd-radius) - 1px) calc(var(--bd-radius) - 1px)0 0;color:var(--accent);cursor:pointer;user-select:none}details[open]>summary{padding:1rem;border-bottom:1px solid var(--bd-muted);margin:-1rem -1rem 1rem;background:var(--bg-muted)}summary>:is(h1,h2,h3,h4,h5,h6){display:inline}code,kbd,output,samp,var{border-radius:var(--bd-radius)}code,kbd,samp,var{padding:.2rem .4rem;margin:0;background:var(--bg-muted);font-family:var(--ft-mono);font-size:85%;font-style:inherit;white-space:break-spaces}var{background:var(--bg-accent);color:var(--accent)}kbd,samp{border:1px solid var(--bd-muted)}kbd{border-color:var(--bg-muted);background:var(--bg-subtle)}output{padding:.25rem .5rem;border:2px dashed var(--bd-muted);background:var(--bg-default);font:inherit;line-height:1.5;user-select:all}p,pre{margin:0 auto 1rem}p img{vertical-align:middle}:is(form,label):last-child,:is(p,pre):last-child{margin-bottom:0}pre{position:relative;overflow:auto;padding:1rem;border-radius:var(--bd-radius);background:var(--bg-muted);font-size:.85rem;line-height:1.45;-webkit-text-size-adjust:100%}pre>code{overflow:visible;padding:0;border-radius:0;background:0 0;font-size:inherit}blockquote{padding:.25rem 1rem;border-left:.25rem solid var(--bd-muted);margin:0 0 1rem;color:var(--muted)}blockquote>cite:last-child{display:block;padding-left:2rem;margin-top:.25rem;text-decoration:none}blockquote>cite:last-child::before{content:"— "}figure{display:flex;flex-wrap:wrap;justify-content:space-around}figcaption{display:block;width:100%;margin:1rem 0;color:var(--muted);text-align:center}button:is(.default,.accent,.active,.variant,.success,.attention,.severe,.danger),fieldset:is(.accent,.active,.variant,.success,.attention,.severe,.danger){border-color:currentColor}button.default:not(:disabled):active,button.default:not(:disabled):hover{background:var(--default);color:var(--contrast)}button.accent:not(:disabled):active,button.accent:not(:disabled):hover{background:var(--accent)}button.active:not(:disabled):active,button.active:not(:disabled):hover{background:var(--active)}button.variant:not(:disabled):active,button.variant:not(:disabled):hover{background:var(--variant)}button.success:not(:disabled):active,button.success:not(:disabled):hover{background:var(--success)}button.attention:not(:disabled):active,button.attention:not(:disabled):hover{background:var(--attention)}button.severe:not(:disabled):active,button.severe:not(:disabled):hover{background:var(--severe)}button.danger:not(:disabled):active,button.danger:not(:disabled):hover{background:var(--danger)}fieldset.accent{color:var(--accent)}fieldset.active{color:var(--active)}fieldset.variant{color:var(--variant)}fieldset.success{color:var(--success)}fieldset.attention{color:var(--attention)}fieldset.severe{color:var(--severe)}fieldset.danger{color:var(--danger)}fieldset,form{border-radius:var(--bd-radius)}form{overflow:auto;padding:1rem;margin:0 auto 1rem;background:var(--bg-muted)}form code,menu>li>menu>li:hover{background:var(--bg-subtle)}fieldset{padding:.5rem 1rem;border:2px solid var(--bd-muted);margin-bottom:1rem}legend{padding:0 .5rem;font-weight:600}label{position:relative;display:table;margin:0 0 1rem}label>small{color:var(--muted)}label>small:first-child::after,label>small:first-child::before{content:"\a";white-space:pre}label:has(>:is(input,textarea,button)){cursor:pointer}label:has(>:is(input,textarea,select,button):disabled){color:var(--muted);cursor:not-allowed}label:has(>:is(input,textarea,select):required)::before{position:absolute;left:-.6rem;color:var(--danger);content:"*"}label:has(>textarea){display:block}button,input,select,textarea{display:block;box-sizing:border-box;border:1px solid var(--bd-muted);border-radius:var(--bd-radius);margin-top:.25rem;background:var(--bg-default);color:inherit;cursor:pointer;font-family:inherit;font-size:inherit;line-height:1.5;transition:border-color var(--tr-duration)}textarea{width:calc(100% - 1rem);padding:.5rem;appearance:none;cursor:text;resize:none}input,textarea{width:100%}:is(textarea,select):hover{border-color:var(--accent)}:is(input,textarea,select):disabled{background-color:var(--bg-muted);cursor:not-allowed}select{width:100%;padding:.35rem .5rem;text-transform:none}input{appearance:none}input:not(:disabled):hover{border-color:var(--accent)}input:not([type=radio],[type=checkbox]){position:relative;min-height:1.5rem;padding:.25rem .5rem}input:is([type=radio],[type=checkbox]),input[type=checkbox]::before{display:inline-block;width:1rem;height:1rem;margin:0 .25rem;vertical-align:middle}input[type=checkbox]{border-radius:0}input[type=radio]{border-width:2px;border-radius:50%}input[type=radio]:checked{border-width:.25rem;border-color:var(--accent)}input[type=checkbox]:checked{border-color:var(--accent);background:var(--accent)}input[type=checkbox]:checked::before{position:absolute;margin:0;background:var(--light);content:"";mask:center center/75%no-repeat;mask-image:url(data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTIiIGhlaWdodD0iOSIgdmlld0JveD0iMCAwIDEyIDkiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxwYXRoIGZpbGwtcnVsZT0iZXZlbm9kZCIgY2xpcC1ydWxlPSJldmVub2RkIiBkPSJNMTEuNzgwMyAwLjIxOTYyNUMxMS45MjEgMC4zNjA0MjcgMTIgMC41NTEzMDUgMTIgMC43NTAzMTNDMTIgMC45NDkzMjEgMTEuOTIxIDEuMTQwMTkgMTEuNzgwMyAxLjI4MUw0LjUxODYgOC41NDA0MkM0LjM3Nzc1IDguNjgxIDQuMTg2ODIgOC43NiAzLjk4Nzc0IDguNzZDMy43ODg2NyA4Ljc2IDMuNTk3NzMgOC42ODEgMy40NTY4OSA4LjU0MDQyTDAuMjAxNjIyIDUuMjg2MkMwLjA2ODkyNzcgNS4xNDM4MyAtMC4wMDMzMDkwNSA0Ljk1NTU1IDAuMDAwMTE2NDkzIDQuNzYwOThDMC4wMDM1NTIwNSA0LjU2NjQzIDAuMDgyMzg5NCA0LjM4MDgxIDAuMjIwMDMyIDQuMjQzMjFDMC4zNTc2NjUgNC4xMDU2MiAwLjU0MzM1NSA0LjAyNjgxIDAuNzM3OTcgNC4wMjMzOEMwLjkzMjU4NCA0LjAxOTk0IDEuMTIwOTMgNC4wOTIxNyAxLjI2MzM0IDQuMjI0ODJMMy45ODc3NCA2Ljk0ODM1TDEwLjcxODYgMC4yMTk2MjVDMTAuODU5NSAwLjA3ODk5MjMgMTEuMDUwNCAwIDExLjI0OTUgMEMxMS40NDg1IDAgMTEuNjM5NSAwLjA3ODk5MjMgMTEuNzgwMyAwLjIxOTYyNVoiIGZpbGw9IndoaXRlIi8+Cjwvc3ZnPgo=)}input:is([type=radio],[type=checkbox]):disabled{background-color:var(--bd-muted)}input[type=checkbox]:disabled{border-color:transparent}input[type=range]{height:.5rem;border:transparent;margin:.75rem 0;accent-color:var(--accent);appearance:auto;background:var(--bg-subtle)}input[type=file]::file-selector-button{border:1px solid var(--bd-muted);border-radius:var(--bd-radius);background:0 0;color:var(--accent);font:inherit}input[type=file]:hover::file-selector-button{background:var(--accent);color:var(--light)}button,input:is([type=submit],[type=reset],[type=button],[type=image]){display:inline-block;padding:.25rem .75rem;margin:.25rem .125rem;background:0 0;color:var(--accent);text-transform:none;transition:background var(--tr-duration),color var(--tr-duration),filter var(--tr-duration)}input:is([type=image],[type=file],[type=color]){padding:.25rem}:is(button,input:is([type=submit],[type=reset],[type=button],[type=image])):disabled{cursor:not-allowed;opacity:.5}:is(button,input)[type=reset]{color:var(--danger)}:is(button,input:is([type=submit],[type=reset],[type=button],[type=image])):not(:disabled):active{filter:brightness(80%)}button[type]:not([type=button]),input:is([type=submit],[type=reset]){border-color:currentColor}:where(button,input:is([type=submit],[type=reset],[type=button],[type=image])):not(:disabled):active,:where(button,input:is([type=submit],[type=reset],[type=button],[type=image])):not(:disabled):hover{border-color:transparent;background:var(--accent);color:var(--light)}:is(button,input)[type=reset]:not(:disabled):active,:is(button,input)[type=reset]:not(:disabled):hover{border-color:transparent;background:var(--danger);color:var(--light)}hgroup{padding:.25rem .5rem;border-left:.25rem solid currentColor;margin:0 0 1rem}hgroup>:is(h1,h2,h3,h4,h5,h6)[id]>a:hover::before{right:calc(100% + 1.25rem)}h1,h2,h3,h4,h5,h6{border-bottom:1px solid transparent;margin:0 0 1rem;line-height:1.25}:not(:is(dialog,article,body)>header:first-child)>:is(h1,h2){border-color:var(--bd-muted)}:not(hgroup,blockquote,header)>:is(h1,h2,h3,h4,h5,h6):first-child{margin-top:1.5rem}:is(h1,h2,h3,h4,h5,h6):last-child{margin-bottom:0}h1{font-size:2rem}h2{font-size:1.5rem}h3{font-size:1.25rem}h4{font-size:1rem}h5{font-size:.875rem}h6{color:var(--muted);font-size:.85rem}:is(h1,h2,h3,h4,h5,h6)[id]>a{position:relative;color:inherit}:is(h1,h2,h3,h4,h5,h6)[id]>a:hover{text-decoration:none}:is(h1,h2,h3,h4,h5,h6)[id]>a:hover::before{position:absolute;top:.125rem;right:calc(100% + .25rem);color:var(--muted);content:"#"}:is(h1,h2,h3,h4,h5,h6)[id]:has(>a:hover){border-color:currentColor}dl,ol,ul{padding-left:2rem;margin:0 0 1rem}li>:is(ul,ol,dl){margin:0}dl,dt{padding:0}dt{margin:1rem 0 0;font-weight:600}dd{padding:0 1rem;margin:0 0 1rem}iframe,img,video{max-width:100%;border-radius:var(--bd-radius);margin:auto}iframe{width:100%;border:0}dialog{max-width:min(calc(100% - 4rem),640px);height:fit-content;max-height:calc(100% - 4rem);padding:1rem;border:var(--bd-muted);border-radius:calc(2*var(--bd-radius));background:var(--bg-default);box-shadow:var(--shadow);color:inherit}dialog>header:first-child{padding:0 1rem 1rem;border-bottom:1px solid var(--bd-muted);margin-right:-1rem;margin-left:-1rem}dialog>header:first-child>:is(h1,h2){font-size:1.25rem}dialog>footer:last-child{padding:1rem 1rem 0;border-top:1px solid var(--bd-muted);margin:0-1rem}dialog>footer:last-child>form[method=dialog]{padding:0;margin:0;background:0 0}dialog::backdrop{background:var(--backdrop)}menu{display:flex;flex-direction:column;padding:0;list-style:none}menu>li{position:relative;flex-shrink:0;padding:.375rem .5rem;margin:.5rem .25rem;cursor:pointer}menu>li:hover{border-radius:var(--bd-radius);background-color:var(--bg-muted);color:var(--default);transition:background-color var(--tr-duration),color var(--tr-duration)}menu>li.selected{color:var(--accent)}menu>li.selected::before{position:absolute;top:.25rem;bottom:.25rem;left:-.25rem;border-right:2px solid currentColor;content:""}menu>li.disabled{color:var(--muted);cursor:not-allowed}menu>li>:is(a,a:hover){color:inherit;text-decoration:none}@media (min-width:544px){menu{flex-direction:row;flex-wrap:wrap;border-bottom:1px solid var(--bd-muted)}menu>li.selected::before{top:unset;right:0;bottom:-.5rem;left:0;border-right:none;border-bottom:2px solid currentColor;border-left:none}menu>li>menu>li.selected::before{border-left:2px solid currentColor}}menu>li:has(>menu)::after{content:"▾"}menu>li>menu{position:absolute;z-index:100;top:100%;left:0;display:none;width:max-content;flex-direction:column;padding:.5rem;border:1px solid var(--bd-muted);border-radius:var(--bd-radius);margin:0;background:var(--bg-muted);color:var(--default);opacity:.25;transition:opacity var(--tr-duration)}menu>li>menu>li{max-width:70vw;margin:0}menu>li>menu>li>menu{top:100%}menu>li>menu>li.selected::before{top:calc(.5*var(--bd-radius));bottom:calc(.5*var(--bd-radius));left:0;border-bottom:none}@media (min-width:544px){menu>li>menu>li>menu{top:0;left:100%}}nav{display:flex;margin:0 0 1rem}nav>menu{border-bottom:none;margin:0}nav :is(ul,ol){padding:0 0 0 1rem;margin:0;list-style:none}nav>:is(ul,ol){padding-left:0}nav>ol{display:flex;flex-wrap:wrap}nav>ol>li:not(:last-child):has(>a)::after{display:inline-block;margin:0 .25rem;color:var(--default);content:"/"}nav>ol>li:last-child{color:var(--default);font-weight:600}nav>ol>li:last-child>a{color:inherit}nav ul{position:relative;overflow:hidden;padding:0;color:var(--muted)}nav ul>li{position:relative;padding-left:1.25rem;border-left:1px solid transparent}nav ul>li.disabled>a{color:var(--muted);cursor:not-allowed}nav ul>li:hover{border-color:var(--accent)}nav ul>li.selected{color:var(--default);font-weight:600}nav ul>li.selected>a{color:inherit}nav ul>li::after,nav ul>li::before{position:absolute;left:0;content:""}nav ul>li::before{top:.75rem;width:1rem;height:0;border-top:1px solid var(--bg-subtle)}nav ul>li::after{top:-.75rem;width:0;height:100%;border-left:1px solid var(--bg-subtle)}nav>ul>li::after{top:.75rem}nav>ul>li:last-child::after{display:none}body{max-width:var(--ct-width);padding:0 1.5rem;margin:0 auto;font-family:var(--ft);font-size:var(--ft-size);line-height:1.5}[data-color-scheme],body{background:var(--bg-default);color:var(--default)}header,main{margin:0 0 1rem}body>footer:last-child,body>header:first-child{margin-right:-1.5rem;margin-left:-1.5rem}footer{text-align:right}section{max-width:var(--ct-width);margin:0 auto 2rem}aside{padding:1rem;border-left:4px solid var(--bd-muted);margin:0 0 0 .5rem;color:var(--muted);float:right}aside.left{border-right:4px solid var(--bd-muted);border-left:none;margin:0 .5rem 0 0;float:left}article{display:flex;flex:1 1 0;flex-direction:column;justify-content:space-between;padding:1rem;border:1px solid var(--bd-muted);border-radius:var(--bd-radius);margin:1rem}article>*{width:100%;box-sizing:border-box}article>header:first-child{box-sizing:content-box;padding:0 1rem 1rem;border-bottom:1px solid var(--bd-muted);margin:0-1rem 1rem}article>footer:last-child{box-sizing:content-box;padding:1rem 1rem 0;border-top:1px solid var(--bd-muted);margin:auto -1rem 0}hr{overflow:visible;height:.25em;box-sizing:content-box;padding:0;border:0;margin:1.5em 0;background:var(--bd-muted)}b,strong{font-weight:700}cite,dfn,em,i,q,strong{font-style:italic}q::before{content:"« "}q::after{content:" »"}dfn,em{font-weight:600}cite,u{text-decoration:underline}u>u{text-decoration:underline double}s{text-decoration:line-through}s>s{text-decoration:line-through double}small{font-size:85%}table{display:block;max-width:100%;margin:0 auto 1rem;border-collapse:collapse;border-spacing:0;inline-size:fit-content;overflow-x:auto}.table-responsive{display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));overflow-x:auto}.table-responsive table{display:table}caption{margin-top:.5rem;caption-side:bottom;color:var(--muted)}tbody>tr:nth-child(2n){background:var(--bg-muted)}td,th{padding:.375rem .8125rem;border:1px solid var(--bd-muted)}th{border-color:var(--bg-contrast);background:var(--bg-subtle);font-weight:700}table.center td,th{text-align:center}abbr,button,code,dd,dt,figcaption,h1,h2,h3,h4,h5,h6,legend,li,p,var{hyphens:auto;word-break:break-word}.editor{position:relative;overflow:hidden;width:100%;border-radius:var(--bd-radius);background:var(--bg-muted)}form .editor{background:var(--bg-default)}.editor>div.highlight,.editor>textarea{box-sizing:border-box;padding:1rem;margin:0;background:0 0;font-family:var(--ft-mono);font-size:1rem;line-height:1.5;-webkit-text-size-adjust:100%;white-space:pre-wrap}.editor>textarea{z-index:1;border:1px solid transparent;caret-color:var(--default);color:transparent}.editor>div.highlight{border:0;position:absolute;z-index:0;top:0;left:0;overflow:hidden;width:100%;height:100%;pointer-events:none}.editor>textarea:user-valid{border-color:transparent}.editor:hover>textarea{border-color:var(--accent)}.editor>textarea:focus{border-color:var(--active)}::-webkit-scrollbar{width:.5rem;height:.5rem;background-color:transparent}::-webkit-scrollbar-thumb{border-radius:var(--bd-radius);background-color:var(--muted)}input:not([type=radio],[type=checkbox],[type=range],[type=submit],[type=image]):user-invalid,select:user-invalid,textarea:user-invalid{border:1px solid var(--danger)}input:not([type=radio],[type=checkbox],[type=range],[type=submit],[type=image]):user-valid,select:user-valid,textarea:user-valid{border:1px solid var(--success)}.layout-simple{display:grid;max-width:none;padding:0 1.5rem;column-gap:1.5rem;grid-template-areas:"header""aside1""main""footer";grid-template-rows:auto 1fr auto}.layout-simple>:is(header:first-of-type,footer:last-of-type){display:flex;height:max-content;flex-wrap:wrap;align-items:center;justify-content:center;padding:0 .5rem;margin-bottom:0;background:var(--ly-bg-brand);color:var(--ly-brand);grid-area:header}.layout-simple>:is(header:first-of-type,footer:last-of-type)>*{margin-top:0;margin-bottom:0}.layout-simple>header:first-of-type{position:sticky;z-index:200;top:0;height:var(--ly-header-size);white-space:nowrap}.layout-simple>main:only-of-type{grid-area:main}.layout-simple>footer:last-of-type{overflow:auto;grid-area:footer}.layout-simple>:is(header:first-of-type,footer:last-of-type)>nav>menu{flex-direction:row;justify-content:center}.layout-simple>aside{display:none;padding:0;border-left:none;margin:0;backdrop-filter:blur(100px)}.layout-simple>aside>nav:only-child{position:sticky;top:var(--ly-header-size);overflow:auto;max-height:calc(100vh - var(--ly-header-size));box-sizing:border-box;padding:.5rem;margin:0}.layout-simple>aside:nth-of-type(1):is([data-expandable],[data-expand]){display:block;overflow:hidden;max-height:var(--ly-aside-size-small);margin-right:-1.5rem;margin-left:-1.5rem;grid-area:aside1}.layout-simple>aside:nth-of-type(1)[data-expand],.layout-simple>aside:nth-of-type(1)[data-expandable]:hover,.layout-simple>aside:nth-of-type(1)[data-expandable][data-expand]{max-height:none}.layout-simple>aside:nth-of-type(1)[data-expandable]::before{display:flex;height:var(--ly-aside-size-small);align-items:center;justify-content:center;content:attr(data-expandable);cursor:pointer}.layout-simple>aside:nth-of-type(1)[data-expandable]:hover::before{color:var(--accent)}.layout-simple>aside>nav:only-child>ul{height:100%}.layout-simple>aside a{color:var(--default)}@media (min-width:960px){.layout-simple>aside:nth-of-type(1)[data-expandable]::before{display:none}.layout-simple>aside:nth-of-type(1):is([data-expandable],[data-expand]){overflow:unset;max-height:none;margin-right:0;margin-left:0}.layout-simple:has(>aside:nth-of-type(1)){padding-left:0;grid-template-areas:"header header""aside1 main""footer footer";grid-template-columns:minmax(0,.4fr) 1fr;grid-template-rows:auto 1fr auto}.layout-simple:has(>aside:nth-of-type(1))>:is(header:first-of-type,footer:last-of-type){margin-left:0}.layout-simple>aside:nth-of-type(1){display:block;box-shadow:var(--shadow-r);grid-area:aside1}}@media (min-width:1280px){.layout-simple:has(>aside:nth-of-type(1)){grid-template-areas:"header header header""aside1 main   .""footer footer footer";grid-template-columns:minmax(0,.3fr) 1fr minmax(0,.3fr)}.layout-simple:has(>aside:nth-of-type(2)){padding-right:0;grid-template-areas:"header header header""aside1 main   aside2""footer footer footer"}.layout-simple:has(>aside:nth-of-type(2))>:is(header:first-of-type,footer:last-of-type){margin-right:0}.layout-simple>aside:nth-of-type(2){display:block;box-shadow:var(--shadow-l);grid-area:aside2}}.layout-simple>header:first-of-type>nav{position:absolute;top:0;right:0;left:0;justify-content:center;background:var(--ly-bg-brand);opacity:0;pointer-events:none;transition:opacity var(--tr-duration),top var(--tr-duration)}.layout-simple>header:first-of-type:hover>nav{top:100%;opacity:1;pointer-events:auto}@media (min-width:768px){.layout-simple>header:first-child{justify-content:space-between}.layout-simple>header:first-child>nav{position:static;height:var(--ly-header-size);justify-content:flex-start;opacity:1;pointer-events:auto}}:root,[data-color-scheme=light]{--comment:#57606a;--function:#6639ba;--language:#0550ae;--string:#0a307b;--keyword:#cf2248;--html:#0550ae;--section:#0349b4;--bullet:#953800}[data-color-scheme=dark]{--comment:#8b949e;--function:#d2a8ff;--language:#79c0ff;--string:#a5d6ff;--keyword:#ff7b72;--html:#7ee787;--section:#409eff;--bullet:#ffa657}@media (prefers-color-scheme:dark){:root:not([data-color-scheme=light]){--comment:#8b949e;--function:#d2a8ff;--language:#79c0ff;--string:#a5d6ff;--keyword:#ff7b72;--html:#7ee787;--section:#409eff;--bullet:#ffa657}}.hljs-comment{color:var(--comment)}.hljs-keyword,.hljs-name,.hljs-symbol,.hljs-type{color:var(--keyword)}.hljs-meta,.hljs-title{color:var(--function)}.hljs-attr,.hljs-attribute,.hljs-built_in,.hljs-code,.hljs-literal,.hljs-number,.hljs-operator,.hljs-property,.hljs-selector-class,.hljs-selector-id,.hljs-selector-pseudo{color:var(--language)}.hljs-link,.hljs-regexp,.hljs-string{color:var(--string)}.hljs-selector-attr,.hljs-subst,.hljs-tag,.hljs-title.class_,.hljs-variable{color:var(--default)}.hljs-quote,.hljs-selector-tag,.hljs-tag .hljs-name{color:var(--html)}.hljs-section{color:var(--section);font-weight:700}.hljs-bullet{color:var(--bullet)}.hljs-emphasis{font-style:italic}.hljs-strong{font-weight:700}.hljs-addition{background-color:var(--bg-success);color:var(--success)}.hljs-deletion{background-color:var(--bg-danger);color:var(--danger)}.flash{padding:1rem;border:1px solid transparent;margin:1rem 0}.default,.flash{color:var(--default)}.muted{color:var(--muted)}.accent{color:var(--accent)}.active{color:var(--active)}.variant{color:var(--variant)}.success{color:var(--success)}.attention{color:var(--attention)}.severe{color:var(--severe)}.danger{color:var(--danger)}.bg-default,.flash.default{background-color:var(--bg-default)}.bg-muted,.flash.muted{background-color:var(--bg-muted)}.bg-accent,.flash.accent{background-color:var(--bg-accent)}.bg-active,.flash.active{background-color:var(--bg-active)}.bg-variant,.flash.variant{background-color:var(--bg-variant)}.bg-success,.flash.success{background-color:var(--bg-success)}.bg-attention,.flash.attention{background-color:var(--bg-attention)}.bg-severe,.flash.severe{background-color:var(--bg-severe)}.bg-danger,.flash.danger{background-color:var(--bg-danger)}.fg-accent,.fg-active,.fg-attention,.fg-danger,.fg-muted,.fg-severe,.fg-success,.fg-variant{color:var(--light)}.fg-default{background-color:var(--default);color:var(--contrast)}.fg-muted{background-color:var(--muted)}.fg-accent{background-color:var(--accent)}.fg-active{background-color:var(--active)}.fg-variant{background-color:var(--variant)}.fg-success{background-color:var(--success)}.fg-attention{background-color:var(--attention)}.fg-severe{background-color:var(--severe)}.fg-danger{background-color:var(--danger)}.flash,.rounded{border-radius:var(--bd-radius)}.bd-accent,.bd-active,.bd-attention,.bd-danger,.bd-default,.bd-muted,.bd-severe,.bd-success,.bd-variant{border:1px solid var(--default)}.bd-default,.flash.default{border-color:var(--default)}.bd-muted,.flash.muted{border-color:var(--muted)}.bd-accent,.flash.accent{border-color:var(--accent)}.bd-active,.flash.active{border-color:var(--active)}.bd-variant,.flash.variant{border-color:var(--variant)}.bd-success,.flash.success{border-color:var(--success)}.bd-attention,.flash.attention{border-color:var(--attention)}.bd-severe,.flash.severe{border-color:var(--severe)}.bd-danger,.flash.danger{border-color:var(--danger)}.bold{font-weight:700}.semibold{font-weight:600}.italic{font-style:italic}.underline{text-decoration:underline}.strikethrough{text-decoration:line-through}.uppercase{text-transform:uppercase}.lowercase{text-transform:lowercase}.capitalize{text-transform:capitalize}.centered{text-align:center}.justified{text-align:justify}.monospace{font-family:var(--mono)}.smaller{font-size:.85rem}.small{font-size:.875rem}.normal{font-size:1rem}.large{font-size:1.25rem}.larger{font-size:1.5rem}.relative{position:relative}.fixed{position:fixed}.absolute{position:absolute}.sticky{position:sticky}.hidden{display:none}.inline{display:inline}.block{display:block}.block.inline{display:inline-block}.flex{display:flex}.flex.inline{display:inline-flex}.contents{display:contents}.flex.row{flex-direction:row}.flex.column{flex-direction:column}.flex.row.reverse{flex-direction:row-reverse}.flex.column.reverse{flex-direction:column-reverse}.flex.wrap{flex-wrap:wrap}.flex.wrap.reverse{flex-wrap:wrap-reverse}.flex.no-wrap{flex-wrap:nowrap}.flex.start{justify-content:flex-start}.flex.end{justify-content:flex-end}.flex.center{justify-content:center}.flex.space-between{justify-content:space-between}.flex.space-around{justify-content:space-around}.flex.space-evenly{justify-content:space-evenly}.flex.stretch{justify-content:stretch}.flex.align-start{align-items:flex-start}.flex.align-end{align-items:flex-end}.flex.align-center{align-items:center}.flex.align-stretch{align-items:stretch}.grow{flex-grow:1}.shrink{flex-shrink:1}.overflow{overflow:auto}.overflow-x{overflow-x:auto}.overflow-y{overflow-y:auto}.no-overflow{overflow:hidden}.pointer{cursor:pointer}.wait{cursor:wait}.not-allowed{cursor:not-allowed}.no-select{user-select:none}.select-all{user-select:all}.events{pointer-events:auto}.no-events{pointer-events:none}.width{width:100%}.height{height:100%}.border-box{box-sizing:border-box}.content-box{box-sizing:content-box}.resize{resize:both}.resize-x{resize:horizontal}.resize-y{resize:vertical}.no-resize{resize:none}svg.fill-current{fill:currentColor}svg.no-fill{fill:none}svg.stroke-current{stroke:currentColor}svg.no-stroke{stroke:none}.shadow{box-shadow:var(--shadow)}.no-shadow{box-shadow:none}.m-0{margin:0}.m-\.125{margin:.125rem}.m-\.25{margin:.25rem}.m-\.5{margin:.5rem}.m-\.75{margin:.75rem}.m-1{margin:1rem}.m-1\.25{margin:1.25rem}.m-1\.5{margin:1.5rem}.m-1\.75{margin:1.75rem}.m-2{margin:2rem}.m-3{margin:3rem}.m-4{margin:4rem}.mx-0{margin-right:0;margin-left:0}.mx-\.125{margin-right:.125rem;margin-left:.125rem}.mx-\.25{margin-right:.25rem;margin-left:.25rem}.mx-\.5{margin-right:.5rem;margin-left:.5rem}.mx-\.75{margin-right:.75rem;margin-left:.75rem}.mx-1{margin-right:1rem;margin-left:1rem}.mx-1\.25{margin-right:1.25rem;margin-left:1.25rem}.mx-1\.5{margin-right:1.5rem;margin-left:1.5rem}.mx-1\.75{margin-right:1.75rem;margin-left:1.75rem}.mx-2{margin-right:2rem;margin-left:2rem}.mx-3{margin-right:3rem;margin-left:3rem}.mx-4{margin-right:4rem;margin-left:4rem}.my-0{margin-top:0;margin-bottom:0}.my-\.125{margin-top:.125rem;margin-bottom:.125rem}.my-\.25{margin-top:.25rem;margin-bottom:.25rem}.my-\.5{margin-top:.5rem;margin-bottom:.5rem}.my-\.75{margin-top:.75rem;margin-bottom:.75rem}.my-1{margin-top:1rem;margin-bottom:1rem}.my-1\.25{margin-top:1.25rem;margin-bottom:1.25rem}.my-1\.5{margin-top:1.5rem;margin-bottom:1.5rem}.my-1\.75{margin-top:1.75rem;margin-bottom:1.75rem}.my-2{margin-top:2rem;margin-bottom:2rem}.my-3{margin-top:3rem;margin-bottom:3rem}.my-4{margin-top:4rem;margin-bottom:4rem}.mt-0{margin-top:0}.mt-\.125{margin-top:.125rem}.mt-\.25{margin-top:.25rem}.mt-\.5{margin-top:.5rem}.mt-\.75{margin-top:.75rem}.mt-1{margin-top:1rem}.mt-1\.25{margin-top:1.25rem}.mt-1\.5{margin-top:1.5rem}.mt-1\.75{margin-top:1.75rem}.mt-2{margin-top:2rem}.mt-3{margin-top:3rem}.mt-4{margin-top:4rem}.mr-0{margin-right:0}.mr-\.125{margin-right:.125rem}.mr-\.25{margin-right:.25rem}.mr-\.5{margin-right:.5rem}.mr-\.75{margin-right:.75rem}.mr-1{margin-right:1rem}.mr-1\.25{margin-right:1.25rem}.mr-1\.5{margin-right:1.5rem}.mr-1\.75{margin-right:1.75rem}.mr-2{margin-right:2rem}.mr-3{margin-right:3rem}.mr-4{margin-right:4rem}.mb-0{margin-bottom:0}.mb-\.125{margin-bottom:.125rem}.mb-\.25{margin-bottom:.25rem}.mb-\.5{margin-bottom:.5rem}.mb-\.75{margin-bottom:.75rem}.mb-1{margin-bottom:1rem}.mb-1\.25{margin-bottom:1.25rem}.mb-1\.5{margin-bottom:1.5rem}.mb-1\.75{margin-bottom:1.75rem}.mb-2{margin-bottom:2rem}.mb-3{margin-bottom:3rem}.mb-4{margin-bottom:4rem}.ml-0{margin-left:0}.ml-\.125{margin-left:.125rem}.ml-\.25{margin-left:.25rem}.ml-\.5{margin-left:.5rem}.ml-\.75{margin-left:.75rem}.ml-1{margin-left:1rem}.ml-1\.25{margin-left:1.25rem}.ml-1\.5{margin-left:1.5rem}.ml-1\.75{margin-left:1.75rem}.ml-2{margin-left:2rem}.ml-3{margin-left:3rem}.ml-4{margin-left:4rem}.p-0{padding:0}.p-\.125{padding:.125rem}.p-\.25{padding:.25rem}.p-\.5{padding:.5rem}.p-\.75{padding:.75rem}.p-1{padding:1rem}.p-1\.25{padding:1.25rem}.p-1\.5{padding:1.5rem}.p-1\.75{padding:1.75rem}.p-2{padding:2rem}.p-3{padding:3rem}.p-4{padding:4rem}.px-0{padding-right:0;padding-left:0}.px-\.125{padding-right:.125rem;padding-left:.125rem}.px-\.25{padding-right:.25rem;padding-left:.25rem}.px-\.5{padding-right:.5rem;padding-left:.5rem}.px-\.75{padding-right:.75rem;padding-left:.75rem}.px-1{padding-right:1rem;padding-left:1rem}.px-1\.25{padding-right:1.25rem;padding-left:1.25rem}.px-1\.5{padding-right:1.5rem;padding-left:1.5rem}.px-1\.75{padding-right:1.75rem;padding-left:1.75rem}.px-2{padding-right:2rem;padding-left:2rem}.px-3{padding-right:3rem;padding-left:3rem}.px-4{padding-right:4rem;padding-left:4rem}.py-0{padding-top:0;padding-bottom:0}.py-\.125{padding-top:.125rem;padding-bottom:.125rem}.py-\.25{padding-top:.25rem;padding-bottom:.25rem}.py-\.5{padding-top:.5rem;padding-bottom:.5rem}.py-\.75{padding-top:.75rem;padding-bottom:.75rem}.py-1{padding-top:1rem;padding-bottom:1rem}.py-1\.25{padding-top:1.25rem;padding-bottom:1.25rem}.py-1\.5{padding-top:1.5rem;padding-bottom:1.5rem}.py-1\.75{padding-top:1.75rem;padding-bottom:1.75rem}.py-2{padding-top:2rem;padding-bottom:2rem}.py-3{padding-top:3rem;padding-bottom:3rem}.py-4{padding-top:4rem;padding-bottom:4rem}.pt-0{padding-top:0}.pt-\.125{padding-top:.125rem}.pt-\.25{padding-top:.25rem}.pt-\.5{padding-top:.5rem}.pt-\.75{padding-top:.75rem}.pt-1{padding-top:1rem}.pt-1\.25{padding-top:1.25rem}.pt-1\.5{padding-top:1.5rem}.pt-1\.75{padding-top:1.75rem}.pt-2{padding-top:2rem}.pt-3{padding-top:3rem}.pt-4{padding-top:4rem}.pr-0{padding-right:0}.pr-\.125{padding-right:.125rem}.pr-\.25{padding-right:.25rem}.pr-\.5{padding-right:.5rem}.pr-\.75{padding-right:.75rem}.pr-1{padding-right:1rem}.pr-1\.25{padding-right:1.25rem}.pr-1\.5{padding-right:1.5rem}.pr-1\.75{padding-right:1.75rem}.pr-2{padding-right:2rem}.pr-3{padding-right:3rem}.pr-4{padding-right:4rem}.pb-0{padding-bottom:0}.pb-\.125{padding-bottom:.125rem}.pb-\.25{padding-bottom:.25rem}.pb-\.5{padding-bottom:.5rem}.pb-\.75{padding-bottom:.75rem}.pb-1{padding-bottom:1rem}.pb-1\.25{padding-bottom:1.25rem}.pb-1\.5{padding-bottom:1.5rem}.pb-1\.75{padding-bottom:1.75rem}.pb-2{padding-bottom:2rem}.pb-3{padding-bottom:3rem}.pb-4{padding-bottom:4rem}.pl-0{padding-left:0}.pl-\.125{padding-left:.125rem}.pl-\.25{padding-left:.25rem}.pl-\.5{padding-left:.5rem}.pl-\.75{padding-left:.75rem}.pl-1{padding-left:1rem}.pl-1\.25{padding-left:1.25rem}.pl-1\.5{padding-left:1.5rem}.pl-1\.75{padding-left:1.75rem}.pl-2{padding-left:2rem}.pl-3{padding-left:3rem}.pl-4{padding-left:4rem}.spacing-x-0>*+*{margin-left:0}.spacing-x-\.125>*+*{margin-left:.125rem}.spacing-x-\.25>*+*{margin-left:.25rem}.spacing-x-\.5>*+*{margin-left:.5rem}.spacing-x-\.75>*+*{margin-left:.75rem}.spacing-x-1>*+*{margin-left:1rem}.spacing-x-1\.25>*+*{margin-left:1.25rem}.spacing-x-1\.5>*+*{margin-left:1.5rem}.spacing-x-1\.75>*+*{margin-left:1.75rem}.spacing-x-2>*+*{margin-left:2rem}.spacing-x-3>*+*{margin-left:3rem}.spacing-x-4>*+*{margin-left:4rem}.spacing-y-0>*+*{margin-top:0}.spacing-y-\.125>*+*{margin-top:.125rem}.spacing-y-\.25>*+*{margin-top:.25rem}.spacing-y-\.5>*+*{margin-top:.5rem}.spacing-y-\.75>*+*{margin-top:.75rem}.spacing-y-1>*+*{margin-top:1rem}.spacing-y-1\.25>*+*{margin-top:1.25rem}.spacing-y-1\.5>*+*{margin-top:1.5rem}.spacing-y-1\.75>*+*{margin-top:1.75rem}.spacing-y-2>*+*{margin-top:2rem}.spacing-y-3>*+*{margin-top:3rem}.spacing-y-4>*+*{margin-top:4rem}</style>
	<style>strong{color:var(--danger)}b{color:var(--accent)}h2{border:none}</style>
</head>
<body>
	<a href="#" class="color-scheme flash fg-default" style="position: fixed;bottom: 1rem;right: 1rem;display: flex;justify-content: center;align-items: center;padding: .75rem;">
		<svg class="dark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="20" height="20" style="display: none;"><path d="M8 12a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm0-1.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Zm5.657-8.157a.75.75 0 0 1 0 1.061l-1.061 1.06a.749.749 0 0 1-1.275-.326.749.749 0 0 1 .215-.734l1.06-1.06a.75.75 0 0 1 1.06 0Zm-9.193 9.193a.75.75 0 0 1 0 1.06l-1.06 1.061a.75.75 0 1 1-1.061-1.06l1.06-1.061a.75.75 0 0 1 1.061 0ZM8 0a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0V.75A.75.75 0 0 1 8 0ZM3 8a.75.75 0 0 1-.75.75H.75a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 3 8Zm13 0a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 16 8Zm-8 5a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 13Zm3.536-1.464a.75.75 0 0 1 1.06 0l1.061 1.06a.75.75 0 0 1-1.06 1.061l-1.061-1.06a.75.75 0 0 1 0-1.061ZM2.343 2.343a.75.75 0 0 1 1.061 0l1.06 1.061a.751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018l-1.06-1.06a.75.75 0 0 1 0-1.06Z"></path></svg>
        <svg class="light" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="20" height="20" style="display: none;fill:var(--contrast)"><path d="M9.598 1.591a.749.749 0 0 1 .785-.175 7.001 7.001 0 1 1-8.967 8.967.75.75 0 0 1 .961-.96 5.5 5.5 0 0 0 7.046-7.046.75.75 0 0 1 .175-.786Zm1.616 1.945a7 7 0 0 1-7.678 7.678 5.499 5.499 0 1 0 7.678-7.678Z"></path></svg>
     </a>
     <script>
     	const prefers = matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
		const html = document.querySelector("html");
		html.dataset.colorScheme = prefers;
		document.querySelectorAll(`body .color-scheme`).forEach((element) => {
		  element.querySelector(`svg.${prefers}`).style.display = "inline-block";
		  element.addEventListener("click", (event) => {
		  	event.preventDefault();
		    toggleColorScheme(html.dataset, element);
		  })
		})
		
		function toggleColorScheme(dataset, element) {
		  dataset.colorScheme = (dataset.colorScheme ?? prefers) === "light" ? "dark" : "light";
		  element.querySelector("svg.light").style.display = dataset.colorScheme === "light" ? "inline-block" : "none";
		  element.querySelector("svg.dark").style.display = dataset.colorScheme === "dark" ? "inline-block" : "none";
		}
     </script>
<?php
// Output all differences
if (!empty($schema_differences)) {
    echo "<br><h2>Schema differences:</h2>";
    foreach ($tables2 as $table){
		if (!in_array($table, $tables1)) {
		    echo "Misssing Table <b>$table</b> in <strong>$db1_user</strong><br>";
		}
	}
	foreach ($tables1 as $table){
		if (!in_array($table, $tables2)) {
		    echo "Misssing Table <b>$table</b> in <strong>$db2_user</strong><br>";
		}
	}
    foreach ($schema_differences as $diff) {
        echo $diff . "<br>";
    }
}

if (!empty($index_differences)) {
    echo "<br><hr><h2>Index differences:</h2>";
    foreach ($index_differences as $diff) {
        echo $diff . "<br>";
    }
}

if (!empty($pk_differences)) {
    echo "<hr><br><h2>Primary key differences:</h2>";
    foreach ($pk_differences as $diff) {
        echo $diff . "<br>";
    }
}

if (!empty($fk_differences)) {
    echo "<hr><br><h2>Foreign key differences:</h2>";
    foreach ($fk_differences as $diff) {
        echo $diff . "<br>";
    }
}

// Close the connections
$conn1->close();
$conn2->close();
?>
</body>
</html>