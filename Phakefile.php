<?php
require __DIR__ . '/vendor/autoload.php';

function invoke_wp_cli( $cmd, $app ) {
	ob_start();
	system( "WP_CLI_CONFIG_PATH=/dev/null $cmd", $return_code );
	$json = ob_get_clean();

	if ( $return_code ) {
		echo "WP-CLI returned error code: $return_code\n";
		exit(1);
	}

	return json_decode( $json, true );
}

function render( $path, $binding ) {
	$m = new Mustache_Engine;
	$template = file_get_contents( __DIR__ . "/_templates/$path" );
	return $m->render( $template, $binding );
}

desc( 'Generate a list of commands with all accepted arguments on STDOUT.' );
task( 'syn-list', function( $app ) {
	function generate_synopsis( $command, $path = '' ) {
		$full_path = $path . ' ' . $command['name'];

		if ( !isset( $command['subcommands'] ) ) {
			echo $full_path . ' ' . $command['synopsis'] . "\n";
		} else {
			foreach ( $command['subcommands'] as $subcommand ) {
				generate_synopsis( $subcommand, $full_path );
			}
		}
	}

	generate_synopsis( invoke_wp_cli( 'wp cli cmd-dump', $app ) );
});

function gen_cmd_pages( $cmd, $parent = array() ) {
	$parent[] = $cmd['name'];

	$binding = $cmd;
	$binding['synopsis'] = implode( ' ', $parent );
	$binding['path'] = implode( '/', $parent );
	$binding['has-subcommands'] = isset( $cmd['subcommands'] ) ? array(true) : false;

	if ( $cmd['longdesc'] ) {
		$docs = $cmd['longdesc'];
		$docs = htmlspecialchars( $docs, ENT_COMPAT, 'UTF-8' );

		// decrease header level
		$docs = preg_replace( '/^## /m', '### ', $docs );

		// escape `--` so that it doesn't get converted into `&mdash;`
		$docs = preg_replace( '/^(\[?)--/m', '\1\--', $docs );
		$docs = preg_replace( '/^\s\s--/m', '  \1\--', $docs );

		// hack to prevent double encoding in code blocks
		$docs = preg_replace( '/ &lt; /', ' < ', $docs );
		$docs = preg_replace( '/ &gt; /', ' > ', $docs );
		$docs = preg_replace( '/ &lt;&lt;/', ' <<', $docs );
		$docs = preg_replace( '/&quot;/', '"', $docs );

		$binding['docs'] = $docs;
	}

	$path = __DIR__ . "/commands/" . $binding['path'];
	if ( !is_dir( $path ) ) {
		mkdir( $path );
	}
	file_put_contents( "$path/index.md", render( 'subcmd-list.mustache', $binding ) );

	if ( !isset( $cmd['subcommands'] ) )
		return;

	foreach ( $cmd['subcommands'] as $subcmd ) {
		gen_cmd_pages( $subcmd, $parent );
	}
}

desc( 'Update the /commands/ page.' );
task( 'cmd-list', function( $app ) {
	$wp = invoke_wp_cli( 'wp cli cmd-dump', $app );

	// generate main page
	file_put_contents( '_includes/cmd-list.html', render( 'cmd-list.mustache', $wp ) );

	foreach ( $wp['subcommands'] as $cmd ) {
		gen_cmd_pages( $cmd );
	}
});

desc( 'Update the /config/ page.' );
task( 'param-list', function( $app ) {
	$config_spec = invoke_wp_cli( 'wp cli param-dump', $app );

	$out = '';

	foreach ( $config_spec as $key => $details ) {
		if ( isset( $details['hidden'] ) || isset( $details['deprecated'] ) )
			continue;

		if ( false !== $details['file'] ) {
			$config = "$key: " . $details['file'];
		} else {
			$config = '';
		}

		if ( false !== $details['runtime'] ) {
			$flag = ( true === $details['runtime'] )
				? "--[no-]$key"
				: "--$key" . $details['runtime'];
		} else {
			$flag = '';
		}

		$default = json_encode( $details['default'] );

		$description = ( isset( $details['desc'] ) ) ? $details['desc'] : '';

		$out .= render( 'config.mustache', compact( 'config', 'flag', 'default', 'description' ) );
	}

	file_put_contents( '_includes/param-list.html', $out );
});

desc( 'Update the /docs/internal-api/ page.' );
task( 'internal-api-list', function( $app ) {
	$apis = invoke_wp_cli( 'wp cli api-dump', $app );
	$categories = array(
		'Output' => array(),
		'Input'  => array(),
		'Execution' => array(),
		'Misc'   => array(),
	);

	$prepare_api_slug = function( $full_name ) {
		$replacements = array(
			'()'      => '',
			'::'      => '-',
			'_'       => '-',
			'\\'      => '-',
		);
		return strtolower( str_replace( array_keys( $replacements ), array_values( $replacements ), $full_name ) );
	};

	$prepare_code_block = function( $description ) {
		return preg_replace_callback( '#```(.+)```#Us', function( $matches ) {
			return str_replace( PHP_EOL, PHP_EOL . '    ', $matches[1] );
		}, $description );
	};

	foreach( $apis as $api ) {

		$api['api_slug'] = $prepare_api_slug( $api['full_name'] );
		$api['phpdoc']['long_description'] = $prepare_code_block( $api['phpdoc']['long_description'] );

		if ( ! empty( $api['phpdoc']['parameters']['category'][0][0] )
			&& isset( $categories[ $api['phpdoc']['parameters']['category'][0][0] ] ) ) {
			$categories[ $api['phpdoc']['parameters']['category'][0][0] ][] = $api;
		} else {
			$categories['Misc'][] = $api;
		}
	}
	$out = '***' . PHP_EOL . PHP_EOL;

	foreach( $categories as $name => $apis ) {
		$out .= '## ' . $name . PHP_EOL . PHP_EOL;
		$out .= render( 'internal-api-list.mustache', array( 'apis' => $apis ) );
		foreach( $apis as $i => $api ) {
			$api['category'] = $name;
			$api['related'] = $apis;
			unset( $api['related'][ $i ] );
			$api['related'] = array_values( $api['related'] );
			$api['has_related'] = ! empty( $api['related'] );
			$api_doc = render( 'internal-api.mustache', $api );
			$path = "docs/internal-api/{$api['api_slug']}";
			if ( ! is_dir( $path ) ) {
				mkdir( $path );
			}
			file_put_contents( "$path/index.md", $api_doc );
		}
		$out .= PHP_EOL . PHP_EOL;
	}

	file_put_contents( '_includes/internal-api-list.html', $out );
});

desc( 'Build the site.' );
task( 'default', 'cmd-list', 'param-list', 'internal-api-list' );

