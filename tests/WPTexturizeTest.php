<?php

/**
 * @group formatting
 */
class Tests_Formatting_PAP_WPTexturize extends WP_UnitTestCase {
	function test_dashes() {
		$this->assertEquals('Hey &#8212; boo?', pap_wptexturize('Hey -- boo?'));
		$this->assertEquals('<a href="http://xx--xx">Hey &#8212; boo?</a>', pap_wptexturize('<a href="http://xx--xx">Hey -- boo?</a>'));
	}

	function test_disable() {
		$this->assertEquals('<pre>---&</pre>', pap_wptexturize('<pre>---&</pre>'));
		$this->assertEquals('<pre><code></code>--&</pre>', pap_wptexturize('<pre><code></code>--&</pre>'));

		$this->assertEquals( '<code>---&</code>',     pap_wptexturize( '<code>---&</code>'     ) );
		$this->assertEquals( '<kbd>---&</kbd>',       pap_wptexturize( '<kbd>---&</kbd>'       ) );
		$this->assertEquals( '<style>---&</style>',   pap_wptexturize( '<style>---&</style>'   ) );
		$this->assertEquals( '<script>---&</script>', pap_wptexturize( '<script>---&</script>' ) );
		$this->assertEquals( '<tt>---&</tt>',         pap_wptexturize( '<tt>---&</tt>'         ) );

		$this->assertEquals('<code>href="baba"</code> &#8220;baba&#8221;', pap_wptexturize('<code>href="baba"</code> "baba"'));

		$enabled_tags_inside_code = '<code>curl -s <a href="http://x/">baba</a> | grep sfive | cut -d "\"" -f 10 &gt; topmp3.txt</code>';
		$this->assertEquals($enabled_tags_inside_code, pap_wptexturize($enabled_tags_inside_code));

		$double_nest = '<pre>"baba"<code>"baba"<pre></pre></code>"baba"</pre>';
		$this->assertEquals($double_nest, pap_wptexturize($double_nest));

		$invalid_nest = '<pre></code>"baba"</pre>';
		$this->assertEquals($invalid_nest, pap_wptexturize($invalid_nest));

	}

	/**
	 * @ticket 1418
	 */
	function test_bracketed_quotes_1418() {
		$this->assertEquals('(&#8220;test&#8221;)', pap_wptexturize('("test")'));
		$this->assertEquals('(&#8216;test&#8217;)', pap_wptexturize("('test')"));
		$this->assertEquals('(&#8217;twas)', pap_wptexturize("('twas)"));
	}

}
