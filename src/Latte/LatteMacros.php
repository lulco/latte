<?php

/**
 * Nette Framework
 *
 * @copyright  Copyright (c) 2004, 2010 David Grudl
 * @license    http://nette.org/license  Nette license
 * @link       http://nette.org
 * @category   Nette
 * @package    Nette\Templates
 */

namespace Nette\Templates;

use Nette,
	Nette\String;



/**
 * Default macros for filter LatteFilter.
 *
 * - {$variable} with escaping
 * - {!$variable} without escaping
 * - {*comment*} will be removed
 * - {=expression} echo with escaping
 * - {!=expression} echo without escaping
 * - {?expression} evaluate PHP statement
 * - {_expression} echo translation with escaping
 * - {!_expression} echo translation without escaping
 * - {link destination ...} control link
 * - {plink destination ...} presenter link
 * - {if ?} ... {elseif ?} ... {else} ... {/if}
 * - {ifset ?} ... {elseifset ?} ... {/if}
 * - {for ?} ... {/for}
 * - {foreach ?} ... {/foreach}
 * - {include ?}
 * - {cache ?} ... {/cache} cached block
 * - {snippet ?} ... {/snippet ?} control snippet
 * - {attr ?} HTML element attributes
 * - {block|texy} ... {/block} block
 * - {contentType ...} HTTP Content-Type header
 * - {status ...} HTTP status
 * - {capture ?} ... {/capture} capture block to parameter
 * - {var var => value} set template parameter
 * - {assign var => value} set template parameter
 * - {default var => value} set default template parameter
 * - {dump $var}
 * - {debugbreak}
 *
 * @copyright  Copyright (c) 2004, 2010 David Grudl
 * @package    Nette\Templates
 */
class LatteMacros extends Nette\Object
{
	/** @var array */
	public static $defaultMacros = array(
		'syntax' => '%:macroSyntax%',
		'/syntax' => '%:macroSyntax%',

		'block' => '<?php %:macroBlock% ?>',
		'/block' => '<?php %:macroBlockEnd% ?>',

		'capture' => '<?php %:macroCapture% ?>',
		'/capture' => '<?php %:macroCaptureEnd% ?>',

		'snippet' => '<?php %:macroSnippet% ?>',
		'/snippet' => '<?php %:macroSnippetEnd% ?>',

		'cache' => '<?php if ($_cb->foo = Nette\Templates\CachingHelper::create($_cb->key = md5(__FILE__) . __LINE__, $template->getFile(), array(%%))) { $_cb->caches[] = $_cb->foo ?>',
		'/cache' => '<?php array_pop($_cb->caches)->save(); } if (!empty($_cb->caches)) end($_cb->caches)->addItem($_cb->key) ?>',

		'if' => '<?php if (%%): ?>',
		'elseif' => '<?php elseif (%%): ?>',
		'else' => '<?php else: ?>',
		'/if' => '<?php endif ?>',
		'ifset' => '<?php if (isset(%%)): ?>',
		'/ifset' => '<?php endif ?>',
		'elseifset' => '<?php elseif (isset(%%)): ?>',
		'foreach' => '<?php foreach (%:macroForeach%): ?>',
		'/foreach' => '<?php endforeach; array_pop($_cb->its); $iterator = end($_cb->its) ?>',
		'for' => '<?php for (%%): ?>',
		'/for' => '<?php endfor ?>',
		'while' => '<?php while (%%): ?>',
		'/while' => '<?php endwhile ?>',
		'continueIf' => '<?php if (%%) continue ?>',
		'breakIf' => '<?php if (%%) break ?>',

		'include' => '<?php %:macroInclude% ?>',
		'extends' => '<?php %:macroExtends% ?>',
		'layout' => '<?php %:macroExtends% ?>',

		'plink' => '<?php echo %:macroEscape%(%:macroPlink%) ?>',
		'link' => '<?php echo %:macroEscape%(%:macroLink%) ?>',
		'ifCurrent' => '<?php %:macroIfCurrent%; if ($presenter->getLastCreatedRequestFlag("current")): ?>',
		'widget' => '<?php %:macroWidget% ?>',
		'control' => '<?php %:macroWidget% ?>',

		'attr' => '<?php echo Nette\Web\Html::el(NULL)->%:macroAttr%attributes() ?>',
		'contentType' => '<?php %:macroContentType% ?>',
		'status' => '<?php Nette\Environment::getHttpResponse()->setCode(%%) ?>',
		'var' => '<?php %:macroAssign% ?>',
		'assign' => '<?php %:macroAssign% ?>',
		'default' => '<?php %:macroDefault% ?>',
		'dump' => '<?php Nette\Debug::barDump(%:macroDump%, "Template " . str_replace(Nette\Environment::getVariable("appDir"), "\xE2\x80\xA6", $template->getFile())) ?>',
		'debugbreak' => '<?php if (function_exists("debugbreak")) debugbreak(); elseif (function_exists("xdebug_break")) xdebug_break() ?>',

		'!_' => '<?php echo %:macroTranslate% ?>',
		'_' => '<?php echo %:macroEscape%(%:macroTranslate%) ?>',
		'!=' => '<?php echo %:macroModifiers% ?>',
		'=' => '<?php echo %:macroEscape%(%:macroModifiers%) ?>',
		'!$' => '<?php echo %:macroVar% ?>',
		'$' => '<?php echo %:macroEscape%(%:macroVar%) ?>',
		'?' => '<?php %:macroModifiers% ?>',
	);

	/** @var array */
	public $macros;

	/** @var LatteFilter */
	private $filter;

	/** @var array */
	private $current;

	/** @var array */
	private $blocks = array();

	/** @var array */
	private $namedBlocks = array();

	/** @var bool */
	private $extends;

	/** @var string */
	private $uniq;

	/** @var bool */
	private $oldSnippetMode = TRUE;

	/**#@+ @internal block type */
	const BLOCK_NAMED = 1;
	const BLOCK_CAPTURE = 2;
	const BLOCK_ANONYMOUS = 3;
	/**#@-*/



	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->macros = self::$defaultMacros;
	}



	/**
	 * Initializes parsing.
	 * @param  LatteFilter
	 * @param  string
	 * @return void
	 */
	public function initialize($filter, & $s)
	{
		$this->filter = $filter;
		$this->blocks = array();
		$this->namedBlocks = array();
		$this->extends = NULL;
		$this->uniq = substr(md5(uniqid('', TRUE)), 0, 10);

		$filter->context = LatteFilter::CONTEXT_TEXT;
		$filter->escape = 'Nette\Templates\TemplateHelpers::escapeHtml';

		// remove comments
		$s = String::replace($s, '#\\{\\*.*?\\*\\}[\r\n]*#s', '');

		// snippets support (temporary solution)
		$s = String::replace(
			$s,
			'#@(\\{[^}]+?\\})#s',
			'<?php } ?>$1<?php if (Nette\Templates\SnippetHelper::$outputAllowed) { ?>'
		);
	}



	/**
	 * Finishes parsing.
	 * @param  string
	 * @return void
	 */
	public function finalize(& $s)
	{
		// blocks closing check
		if (count($this->blocks) === 1) { // auto-close last block
			$s .= $this->macro('/block', '', '');

		} elseif ($this->blocks) {
			throw new \InvalidStateException("There are some unclosed blocks.");
		}

		// snippets support (temporary solution)
		$s = "<?php\nif (" . 'Nette\Templates\SnippetHelper::$outputAllowed' . ") {\n?>$s<?php\n}\n?>";

		// extends support
		if ($this->namedBlocks || $this->extends) {
			$s = "<?php\n"
				. 'if ($_cb->extends) { ob_start(); }' . "\n"
				. 'elseif (isset($presenter, $control) && $presenter->isAjax()) { Nette\Templates\LatteMacros::renderSnippets($control, $_cb, get_defined_vars()); }' . "\n"
				. '?>' . $s . "<?php\n"
				. 'if ($_cb->extends) { ob_end_clean(); Nette\Templates\LatteMacros::includeTemplate($_cb->extends, get_defined_vars(), $template)->render(); }' . "\n";
		} else {
			$s = "<?php\n"
				. 'if (isset($presenter, $control) && $presenter->isAjax()) { Nette\Templates\LatteMacros::renderSnippets($control, $_cb, get_defined_vars()); }' . "\n"
				. '?>' . $s;
		}

		// named blocks
		if ($this->namedBlocks) {
			foreach (array_reverse($this->namedBlocks, TRUE) as $name => $foo) {
				$name = preg_quote($name, '#');
				$s = String::replace($s, "#{block ($name)} \?>(.*)<\?php {/block $name}#sU", callback($this, 'cbNamedBlocks'));
			}
			$s = "<?php\n\n" . implode("\n\n\n", $this->namedBlocks) . "\n\n//\n// end of blocks\n//\n?>" . $s;
		}

		// internal state holder
		$s = "<?php\n"
			. '$_cb = Nette\Templates\LatteMacros::initRuntime($template, ' . var_export($this->extends, TRUE) . ', ' . var_export($this->uniq, TRUE) . "); unset(\$_extends);\n"
			. '?>' . $s;
	}



	/**
	 * Process {macro content | modifiers}
	 * @param  string
	 * @param  string
	 * @param  string
	 * @return string
	 */
	public function macro($macro, $content, $modifiers)
	{
		if ($macro === '') {
			$macro = substr($content, 0, 2);
			if (!isset($this->macros[$macro])) {
				$macro = substr($content, 0, 1);
				if (!isset($this->macros[$macro])) {
					return NULL;
				}
			}
			$content = substr($content, strlen($macro));

		} elseif (!isset($this->macros[$macro])) {
			return NULL;
		}
		$this->current = array($content, $modifiers);
		return String::replace($this->macros[$macro], '#%(.*?)%#', callback($this, 'cbMacro'));
	}



	/**
	 * Callback for self::macro().
     * @internal
	 */
	public function cbMacro($m)
	{
		list($content, $modifiers) = $this->current;
		if ($m[1]) {
			return callback($m[1][0] === ':' ? array($this, substr($m[1], 1)) : $m[1])
				->invoke($content, $modifiers);
		} else {
			return $content;
		}
	}



	/**
	 * Process <n:tag attr> (experimental).
	 * @param  string
	 * @param  array
	 * @param  bool
	 * @return string
	 */
	public function tagMacro($name, $attrs, $closing)
	{
		$knownTags = array(
			'include' => 'block',
			'for' => 'each',
			'block' => 'name',
			'if' => 'cond',
			'elseif' => 'cond',
		);
		return $this->macro(
			$closing ? "/$name" : $name,
			isset($knownTags[$name], $attrs[$knownTags[$name]]) ? $attrs[$knownTags[$name]] : substr(var_export($attrs, TRUE), 8, -1),
			isset($attrs['modifiers']) ? $attrs['modifiers'] : ''
		);
	}



	/**
	 * Process <tag n:attr> (experimental).
	 * @param  string
	 * @param  array
	 * @param  bool
	 * @return string
	 */
	public function attrsMacro($code, $attrs, $closing)
	{
		$left = $right = '';
		foreach ($this->macros as $name => $foo) {
			if (!isset($this->macros["/$name"])) { // must be pair-macro
				continue;
			}

			$macro = $closing ? "/$name" : $name;
			if (isset($attrs[$name])) {
				if ($closing) {
					$right .= $this->macro($macro, '', '');
				} else {
					$left = $this->macro($macro, $attrs[$name], '') . $left;
				}
			}

			$innerName = "inner-$name";
			if (isset($attrs[$innerName])) {
				if ($closing) {
					$left .= $this->macro($macro, '', '');
				} else {
					$right = $this->macro($macro, $attrs[$innerName], '') . $right;
				}
			}

			$tagName = "tag-$name";
			if (isset($attrs[$tagName])) {
				$left = $this->macro($name, $attrs[$tagName], '') . $left;
				$right .= $this->macro("/$name", '', '');
			}

			unset($attrs[$name], $attrs[$innerName], $attrs[$tagName]);
		}

		return $attrs ? NULL : $left . $code . $right;
	}



	/********************* macros ****************d*g**/



	/**
	 * {$var |modifiers}
	 */
	public function macroVar($var, $modifiers)
	{
		return LatteFilter::formatModifiers('$' . $var, $modifiers);
	}



	/**
	 * {_$var |modifiers}
	 */
	public function macroTranslate($var, $modifiers)
	{
		return LatteFilter::formatModifiers($var, 'translate|' . $modifiers);
	}



	/**
	 * {syntax ...}
	 */
	public function macroSyntax($var)
	{
		switch ($var) {
		case '':
		case 'latte':
			$this->filter->setDelimiters('\\{(?![\\s\'"{}])', '\\}'); // {...}
			break;

		case 'double':
			$this->filter->setDelimiters('\\{\\{(?![\\s\'"{}])', '\\}\\}'); // {{...}}
			break;

		case 'asp':
			$this->filter->setDelimiters('<%\s*', '\s*%>'); /* <%...%> */
			break;

		case 'python':
			$this->filter->setDelimiters('\\{[{%]\s*', '\s*[%}]\\}'); // {% ... %} | {{ ... }}
			break;

		case 'off':
			$this->filter->setDelimiters('[^\x00-\xFF]', '');
			break;

		default:
			throw new \InvalidStateException("Unknown macro syntax '$var' on line {$this->filter->line}.");
		}
	}



	/**
	 * {include ...}
	 */
	public function macroInclude($content, $modifiers, $isDefinition = FALSE)
	{
		$destination = LatteFilter::fetchToken($content); // destination [,] [params]
		$params = LatteFilter::formatArray($content) . ($content ? ' + ' : '');

		if ($destination === NULL) {
			throw new \InvalidStateException("Missing destination in {include} on line {$this->filter->line}.");

		} elseif ($destination[0] === '#') { // include #block
			$destination = ltrim($destination, '#');
			if (!String::match($destination, '#^' . LatteFilter::RE_IDENTIFIER . '$#')) {
				throw new \InvalidStateException("Included block name must be alphanumeric string, '$destination' given on line {$this->filter->line}.");
			}

			$parent = $destination === 'parent';
			if ($destination === 'parent' || $destination === 'this') {
				$item = end($this->blocks);
				while ($item && $item[0] !== self::BLOCK_NAMED) $item = prev($this->blocks);
				if (!$item) {
					throw new \InvalidStateException("Cannot include $destination block outside of any block on line {$this->filter->line}.");
				}
				$destination = $item[1];
			}
			$name = var_export($destination, TRUE);
			$params .= $isDefinition ? 'get_defined_vars()' : '$template->getParams()';
			$cmd = isset($this->namedBlocks[$destination]) && !$parent
				? "call_user_func(reset(\$_cb->blocks[$name]), \$_cb, $params)"
				: 'Nette\Templates\LatteMacros::callBlock' . ($parent ? 'Parent' : '') . "(\$_cb, $name, $params)";
			return $modifiers
				? "ob_start(); $cmd; echo " . LatteFilter::formatModifiers('ob_get_clean()', $modifiers)
				: $cmd;

		} else { // include "file"
			$destination = LatteFilter::formatString($destination);
			$params .= '$template->getParams()';
			return $modifiers
				? 'echo ' . LatteFilter::formatModifiers('Nette\Templates\LatteMacros::includeTemplate' . "($destination, $params, \$_cb->templates[" . var_export($this->uniq, TRUE) . '])->__toString(TRUE)', $modifiers)
				: 'Nette\Templates\LatteMacros::includeTemplate' . "($destination, $params, \$_cb->templates[" . var_export($this->uniq, TRUE) . '])->render()';
		}
	}



	/**
	 * {extends ...}
	 */
	public function macroExtends($content)
	{
		$destination = LatteFilter::fetchToken($content); // destination
		if ($destination === NULL) {
			throw new \InvalidStateException("Missing destination in {extends} on line {$this->filter->line}.");
		}
		if (!empty($this->blocks)) {
			throw new \InvalidStateException("{extends} must be placed outside any block; on line {$this->filter->line}.");
		}
		if ($this->extends !== NULL) {
			throw new \InvalidStateException("Multiple {extends} declarations are not allowed; on line {$this->filter->line}.");
		}
		$this->extends = $destination !== 'none';
		return $this->extends ? '$_cb->extends = ' . LatteFilter::formatString($destination) : '';
	}



	/**
	 * {block ...}
	 */
	public function macroBlock($content, $modifiers)
	{
		$name = LatteFilter::fetchToken($content); // block [,] [params]

		if ($name === NULL) { // anonymous block
			$this->blocks[] = array(self::BLOCK_ANONYMOUS, NULL, $modifiers);
			return $modifiers === '' ? '' : 'ob_start()';

		} else { // #block
			$name = ltrim($name, '#');
			if (!String::match($name, '#^' . LatteFilter::RE_IDENTIFIER . '$#')) {
				throw new \InvalidStateException("Block name must be alphanumeric string, '$name' given on line {$this->filter->line}.");

			} elseif (isset($this->namedBlocks[$name])) {
				throw new \InvalidStateException("Cannot redeclare block '$name'; on line {$this->filter->line}.");
			}

			$top = empty($this->blocks);
			$this->namedBlocks[$name] = $name;
			$this->blocks[] = array(self::BLOCK_NAMED, $name, '');
			if ($name[0] === '_') { // snippet - experimental
				$tag = LatteFilter::fetchToken($content);  // [name [,]] [tag]
				$tag = trim($tag, '<>');
				$namePhp = var_export(substr($name, 1), TRUE);
				if (!$tag) $tag = 'div';
				return "?><$tag id=\"<?php echo \$control->getSnippetId($namePhp) ?>\"><?php " . $this->macroInclude('#' . $name, $modifiers) . " ?></$tag><?php {block $name}";

			} elseif (!$top) {
				return $this->macroInclude('#' . $name, $modifiers, TRUE) . "{block $name}";

			} elseif ($this->extends) {
				return "{block $name}";

			} else {
				return 'if (!$_cb->extends) { ' . $this->macroInclude('#' . $name, $modifiers, TRUE) . "; } {block $name}";
			}
		}
	}



	/**
	 * {/block}
	 */
	public function macroBlockEnd($content)
	{
		list($type, $name, $modifiers) = array_pop($this->blocks);

		if ($type === self::BLOCK_CAPTURE) { // capture - back compatibility
			$this->blocks[] = array($type, $name, $modifiers);
			return $this->macroCaptureEnd($content);
		}

		if (($type !== self::BLOCK_NAMED && $type !== self::BLOCK_ANONYMOUS) || ($content && $content !== $name)) {
			throw new \InvalidStateException("Tag {/block $content} was not expected here on line {$this->filter->line}.");

		} elseif ($type === self::BLOCK_NAMED) { // block
			return "{/block $name}";

		} else { // anonymous block
			return $modifiers === '' ? '' : 'echo ' . LatteFilter::formatModifiers('ob_get_clean()', $modifiers);
		}
	}



	/**
	 * {snippet ...}
	 */
	public function macroSnippet($content)
	{
		if (substr($content, 0, 1) === ':' || !$this->oldSnippetMode) { // experimental behaviour
			$this->oldSnippetMode = FALSE;
			return $this->macroBlock('_' . ltrim($content, ':'), '');
		}
		$args = array('');
		if ($snippet = LatteFilter::fetchToken($content)) {  // [name [,]] [tag]
			$args[] = LatteFilter::formatString($snippet);
		}
		if ($content) {
			$args[] = LatteFilter::formatString($content);
		}
		return '} if ($_cb->foo = Nette\Templates\SnippetHelper::create($control' . implode(', ', $args) . ')) { $_cb->snippets[] = $_cb->foo';
	}



	/**
	 * {snippet ...}
	 */
	public function macroSnippetEnd($content)
	{
		if (!$this->oldSnippetMode) {
			return $this->macroBlockEnd('', '');
		}
		return 'array_pop($_cb->snippets)->finish(); } if (Nette\Templates\SnippetHelper::$outputAllowed) {';
	}



	/**
	 * {capture ...}
	 */
	public function macroCapture($content, $modifiers)
	{
		$name = LatteFilter::fetchToken($content); // $variable

		if (substr($name, 0, 1) !== '$') {
			throw new \InvalidStateException("Invalid capture block parameter '$name' on line {$this->filter->line}.");
		}

		$this->blocks[] = array(self::BLOCK_CAPTURE, $name, $modifiers);
		return 'ob_start()';
	}



	/**
	 * {/capture}
	 */
	public function macroCaptureEnd($content)
	{
		list($type, $name, $modifiers) = array_pop($this->blocks);

		if ($type !== self::BLOCK_CAPTURE || ($content && $content !== $name)) {
			throw new \InvalidStateException("Tag {/capture $content} was not expected here on line {$this->filter->line}.");
		}

		return $name . '=' . LatteFilter::formatModifiers('ob_get_clean()', $modifiers);
	}



	/**
	 * Converts {block named}...{/block} to functions.
     * @internal
	 */
	public function cbNamedBlocks($matches)
	{
		list(, $name, $content) = $matches;
		$func = '_cbb' . substr(md5($this->uniq . $name), 0, 10) . '_' . preg_replace('#[^a-z0-9_]#i', '_', $name);
		$this->namedBlocks[$name] = "//\n// block $name\n//\n"
			. "if (!function_exists(\$_cb->blocks[" . var_export($name, TRUE) . "][] = '$func')) { function $func(\$_cb, \$_args) { extract(\$_args)\n?>$content<?php\n}}";
		return '';
	}



	/**
	 * {foreach ...}
	 */
	public function macroForeach($content)
	{
		return '$iterator = $_cb->its[] = new Nette\SmartCachingIterator(' . preg_replace('# +as +#i', ') as ', $content, 1);
	}



	/**
	 * {attr ...}
	 */
	public function macroAttr($content)
	{
		return String::replace($content . ' ', '#\)\s+#', ')->');
	}



	/**
	 * {contentType ...}
	 */
	public function macroContentType($content)
	{
		if (strpos($content, 'html') !== FALSE) {
			$this->filter->escape = 'Nette\Templates\TemplateHelpers::escapeHtml';
			$this->filter->context = LatteFilter::CONTEXT_TEXT;

		} elseif (strpos($content, 'xml') !== FALSE) {
			$this->filter->escape = 'Nette\Templates\TemplateHelpers::escapeXml';
			$this->filter->context = LatteFilter::CONTEXT_NONE;

		} elseif (strpos($content, 'javascript') !== FALSE) {
			$this->filter->escape = 'Nette\Templates\TemplateHelpers::escapeJs';
			$this->filter->context = LatteFilter::CONTEXT_NONE;

		} elseif (strpos($content, 'css') !== FALSE) {
			$this->filter->escape = 'Nette\Templates\TemplateHelpers::escapeCss';
			$this->filter->context = LatteFilter::CONTEXT_NONE;

		} elseif (strpos($content, 'plain') !== FALSE) {
			$this->filter->escape = '';
			$this->filter->context = LatteFilter::CONTEXT_NONE;

		} else {
			$this->filter->escape = '$template->escape';
			$this->filter->context = LatteFilter::CONTEXT_NONE;
		}

		// temporary solution
		return strpos($content, '/') ? 'Nette\Environment::getHttpResponse()->setHeader("Content-Type", "' . $content . '")' : '';
	}



	/**
	 * {dump ...}
	 */
	public function macroDump($content)
	{
		return $content ? "array(" . var_export($content, TRUE) . " => $content)" : 'get_defined_vars()';
	}



	/**
	 * {widget ...}
	 */
	public function macroWidget($content)
	{
		$pair = LatteFilter::fetchToken($content); // widget[:method]
		if ($pair === NULL) {
			throw new \InvalidStateException("Missing widget name in {widget} on line {$this->filter->line}.");
		}
		$pair = explode(':', $pair, 2);
		$widget = LatteFilter::formatString($pair[0]);
		$method = isset($pair[1]) ? ucfirst($pair[1]) : '';
		$method = String::match($method, '#^(' . LatteFilter::RE_IDENTIFIER . '|)$#') ? "render$method" : "{\"render$method\"}";
		$param = LatteFilter::formatArray($content);
		if (strpos($content, '=>') === FALSE) $param = substr($param, 6, -1); // removes array()
		return ($widget[0] === '$' ? "if (is_object($widget)) {$widget}->$method($param); else " : '')
			. "\$control->getWidget($widget)->$method($param)";
	}



	/**
	 * {link ...}
	 */
	public function macroLink($content, $modifiers)
	{
		return LatteFilter::formatModifiers('$control->link(' . $this->formatLink($content) .')', $modifiers);
	}



	/**
	 * {plink ...}
	 */
	public function macroPlink($content, $modifiers)
	{
		return LatteFilter::formatModifiers('$presenter->link(' . $this->formatLink($content) .')', $modifiers);
	}



	/**
	 * {ifCurrent ...}
	 */
	public function macroIfCurrent($content)
	{
		return $content ? 'try { $presenter->link(' . $this->formatLink($content) . '); } catch (Nette\Application\InvalidLinkException $e) {}' : '';
	}



	/**
	 * Formats {*link ...} parameters.
	 */
	private function formatLink($content)
	{
		return LatteFilter::formatString(LatteFilter::fetchToken($content)) . LatteFilter::formatArray($content, ', '); // destination [,] args
	}



	/**
	 * {assign ...}
	 */
	public function macroAssign($content, $modifiers)
	{
		if (!$content) {
			throw new \InvalidStateException("Missing arguments in {var} or {assign} on line {$this->filter->line}.");
		}
		if (strpos($content, '=>') === FALSE) { // back compatibility
			return '$' . ltrim(LatteFilter::fetchToken($content), '$') . ' = ' . LatteFilter::formatModifiers($content === '' ? 'NULL' : $content, $modifiers);
		}
		return 'extract(' . LatteFilter::formatArray($content) . ')';
	}



	/**
	 * {default ...}
	 */
	public function macroDefault($content)
	{
		if (!$content) {
			throw new \InvalidStateException("Missing arguments in {default} on line {$this->filter->line}.");
		}
		return 'extract(' . LatteFilter::formatArray($content) . ', EXTR_SKIP)';
	}



	/**
	 * Escaping helper.
	 */
	public function macroEscape($content)
	{
		return $this->filter->escape;
	}



	/**
	 * Just modifiers helper.
	 */
	public function macroModifiers($content, $modifiers)
	{
		return LatteFilter::formatModifiers($content, $modifiers);
	}



	/********************* run-time helpers ****************d*g**/



	/**
	 * Calls block.
	 * @param  stdClass
	 * @param  string
	 * @param  array
	 * @return void
	 */
	public static function callBlock($context, $name, $params)
	{
		if (empty($context->blocks[$name])) {
			throw new \InvalidStateException("Call to undefined block '$name'.");
		}
		$block = reset($context->blocks[$name]);
		$block($context, $params);
	}



	/**
	 * Calls parent block.
	 * @param  stdClass
	 * @param  string
	 * @param  array
	 * @return void
	 */
	public static function callBlockParent($context, $name, $params)
	{
		if (empty($context->blocks[$name]) || ($block = next($context->blocks[$name])) === FALSE) {
			throw new \InvalidStateException("Call to undefined parent block '$name'.");
		}
		$block($context, $params);
	}



	/**
	 * Includes subtemplate.
	 * @param  mixed      included file name or template
	 * @param  array      parameters
	 * @param  ITemplate  current template
	 * @return Template
	 */
	public static function includeTemplate($destination, $params, $template)
	{
		if ($destination instanceof ITemplate) {
			$tpl = $destination;

		} elseif ($destination == NULL) { // intentionally ==
			throw new \InvalidArgumentException("Template file name was not specified.");

		} else {
			$tpl = clone $template;
			if ($template instanceof IFileTemplate) {
				if (substr($destination, 0, 1) !== '/' && substr($destination, 1, 1) !== ':') {
					$destination = dirname($template->getFile()) . '/' . $destination;
				}
				$tpl->setFile($destination);
			}
		}

		$tpl->setParams($params); // interface?
		return $tpl;
	}



	/**
	 * Initializes state holder $_cb in template.
	 * @param  ITemplate
	 * @param  bool
	 * @param  string
	 * @return stdClass
	 */
	public static function initRuntime($template, $extends, $realFile)
	{
		$cb = (object) NULL;

		// extends support
		if (isset($template->_cb)) {
			$cb->blocks = & $template->_cb->blocks;
			$cb->templates = & $template->_cb->templates;
		}
		$cb->templates[$realFile] = $template;
		$cb->extends = is_bool($extends) ? $extends : (empty($template->_extends) ? FALSE : $template->_extends);
		unset($template->_cb, $template->_extends);

		// cache support
		if (!empty($cb->caches)) {
			end($cb->caches)->addFile($template->getFile());
		}

		return $cb;
	}



	public static function renderSnippets($control, $cb, $params)
	{
		$payload = $control->getPresenter()->getPayload();
		if (isset($cb->blocks)) {
			foreach ($cb->blocks as $name => $function) {
				if ($name[0] !== '_' || !$control->isControlInvalid(substr($name, 1))) continue;
				ob_start();
				$function = reset($function);
				$function($cb, $params);
				$payload->snippets[$control->getSnippetId(substr($name, 1))] = ob_get_clean();
			}
		}
	}

}
