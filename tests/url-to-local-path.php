<?php
/**
 * Regression tests for upload URL boundary validation.
 *
 * Run with: php tests/url-to-local-path.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

// Loading the plugin registers hooks. WordPress is not needed for these pure
// path-mapping tests, so keep the registration functions as no-ops.
function add_action(...$args): void {
}

function add_filter(...$args): void {
}

require dirname(__DIR__) . '/wp-auto-webp-converter-plugin.php';

function expect_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . PHP_EOL .
			'Expected: ' . var_export($expected, true) . PHP_EOL .
			'Actual:   ' . var_export($actual, true)
		);
	}
}

function remove_tree(string $path): void {
	if (!is_dir($path)) {
		return;
	}
	$items = scandir($path);
	if (false === $items) {
		return;
	}
	foreach ($items as $item) {
		if ('.' === $item || '..' === $item) {
			continue;
		}
		$child = $path . DIRECTORY_SEPARATOR . $item;
		if (is_dir($child)) {
			remove_tree($child);
		} else {
			unlink($child);
		}
	}
	rmdir($path);
}

$upload_dir = sys_get_temp_dir() . '/wp-auto-webp-' . bin2hex(random_bytes(6));
$local_file = $upload_dir . '/2026/07/image.jpg';
$host_trap  = $upload_dir . '/.evil/wp-content/uploads/2026/07/image.jpg';
$path_trap  = $upload_dir . '/-archive/2026/07/image.jpg';

foreach (array(dirname($local_file), dirname($host_trap), dirname($path_trap)) as $dir) {
	if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
		throw new RuntimeException('Could not create test directory: ' . $dir);
	}
}
foreach (array($local_file, $host_trap, $path_trap) as $file) {
	file_put_contents($file, 'fixture');
}

register_shutdown_function('remove_tree', $upload_dir);

$base_url = 'https://example.test/wp-content/uploads';

expect_same(
	$local_file,
	\WPAutoWebP\url_to_local_path(
		'https://example.test/wp-content/uploads/2026/07/image.jpg?version=1#hero',
		$base_url,
		$upload_dir
	),
	'An exact uploads URL should map to its local file.'
);

expect_same(
	$local_file,
	\WPAutoWebP\url_to_local_path(
		'http://example.test/wp-content/uploads/2026/07/image.jpg',
		$base_url,
		$upload_dir
	),
	'HTTP and HTTPS forms of the same uploads URL should match.'
);

expect_same(
	$local_file,
	\WPAutoWebP\url_to_local_path(
		'//example.test/wp-content/uploads/2026/07/image.jpg',
		$base_url,
		$upload_dir
	),
	'A scheme-relative uploads URL should match.'
);

expect_same(
	null,
	\WPAutoWebP\url_to_local_path(
		'https://example.test.evil/wp-content/uploads/2026/07/image.jpg',
		$base_url,
		$upload_dir
	),
	'A lookalike host must not be treated as the local uploads host.'
);

expect_same(
	null,
	\WPAutoWebP\url_to_local_path(
		'https://example.test/wp-content/uploads-archive/2026/07/image.jpg',
		$base_url,
		$upload_dir
	),
	'A sibling path sharing the uploads prefix must not be accepted.'
);

expect_same(
	null,
	\WPAutoWebP\url_to_local_path(
		'https://example.test/wp-content/uploads/../private/image.jpg',
		$base_url,
		$upload_dir
	),
	'Directory traversal must remain blocked.'
);

expect_same(
	null,
	\WPAutoWebP\url_to_local_path(
		'https://example.test/wp-content/uploads/2026/07/missing.jpg',
		$base_url,
		$upload_dir
	),
	'A missing local file must not be mapped.'
);

echo "URL boundary tests passed.\n";
