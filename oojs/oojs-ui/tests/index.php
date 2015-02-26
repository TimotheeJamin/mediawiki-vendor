<?php
	$autoload = '../vendor/autoload.php';
	if ( !file_exists( $autoload ) ) {
		echo '<h1>Did you forget to run <code>composer install</code>?</h1>';
		exit;
	}
	require_once $autoload;

	$testSuiteFile = 'JSPHP-suite.json';
	if ( !file_exists( $testSuiteFile ) ) {
		echo '<h1>Did you forget to run <code>grunt build</code>?</h1>';
		exit;
	}
	$testSuiteJSON = file_get_contents( $testSuiteFile );
	$testSuite = json_decode( $testSuiteJSON, true );
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
	<meta charset="UTF-8">
	<title>OOjs UI Test Suite</title>
	<link rel="stylesheet" href="../node_modules/qunitjs/qunit/qunit.css">
	<script src="../node_modules/qunitjs/qunit/qunit.js"></script>
	<script src="./QUnit.assert.equalDomElement.js"></script>
	<script>
		QUnit.config.requireExpects = true;
	</script>
	<!-- Dependencies -->
	<script src="../lib/jquery.js"></script>
	<script src="../lib/oojs.jquery.js"></script>
	<!-- Source code -->
	<script src="../dist/oojs-ui.js"></script>
	<script src="../dist/oojs-ui-apex.js"></script>
	<script src="../dist/oojs-ui-mediawiki.js"></script>
	<!-- Test suites -->
	<script src="./Element.test.js"></script>
	<script src="./Process.test.js"></script>
	<script src="./elements/FlaggedElement.test.js"></script>
	<!-- JS/PHP comparison tests -->
	<script>OO.ui.JSPHPTestSuite = <?php echo $testSuiteJSON; ?></script>
	<script src="./JSPHP.test.standalone.js"></script>
</head>
<body>
	<div id="JSPHPTestSuite" style="display: none;">
		<?php
			// Keep synchronized with bin/generate-JSPHP-for-karma.php
			$themes = array( 'ApexTheme', 'MediaWikiTheme' );
			foreach ( $themes as $theme ) {
				$class = "OOUI\\" . $theme;
				OOUI\Theme::setSingleton( new $class() );
				foreach ( $testSuite as $className => $tests ) {
					foreach ( $tests as $index => $test ) {
						$class = "OOUI\\" . $test['class'];
						$instance = new $class( $test['config'] );
						echo "<div id='JSPHPTestSuite_$theme$className$index'>$instance</div>\n";
					}
				}
			}
		?>
	</div>
	<div id="qunit"></div>
	<div id="qunit-fixture"></div>
</body>
</html>
