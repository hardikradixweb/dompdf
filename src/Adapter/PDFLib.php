<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

namespace Dompdf\Adapter;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\Exception;
use Dompdf\FontMetrics;
use Dompdf\Helpers;
use Dompdf\Image\Cache;

/**
 * PDF rendering interface
 *
 * Dompdf\Adapter\PDFLib provides a simple, stateless interface to the one
 * provided by PDFLib.
 *
 * Unless otherwise mentioned, all dimensions are in points (1/72 in).
 * The coordinate origin is in the top left corner and y values
 * increase downwards.
 *
 * See {@link http://www.pdflib.com/} for more complete documentation
 * on the underlying PDFlib functions.
 *
 * @package dompdf
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/lib/pdflib/'.PHP_MAJOR_VERSION .'.' . PHP_MINOR_VERSION.'/PDFLib.php';
class PDFLib extends \OPSPDFLib implements Canvas
{

    /**
     * Dimensions of paper sizes in points
     *
     * @var array
     */
    static public $PAPER_SIZES = array(); // Set to Dompdf\Adapter\CPDF::$PAPER_SIZES below.

    /**
     * Whether to create PDFs in memory or on disk
     *
     * @var bool
     */
    static $IN_MEMORY = true;

    /**
     * Saves the major version of PDFLib for compatibility requests
     *
     * @var null|int
     */
    static private $MAJOR_VERSION = null;


    /**
     * Transforms the list of native fonts into PDFLib compatible names (casesensitive)
     *
     * @var array
     */
    public static $nativeFontsToPDFLib = [
        "courier"               => "Courier",
        "courier-bold"          => "Courier-Bold",
        "courier-oblique"       => "Courier-Oblique",
        "courier-boldoblique"   => "Courier-BoldOblique",
        "helvetica"             => "Helvetica",
        "helvetica-bold"        => "Helvetica-Bold",
        "helvetica-oblique"     => "Helvetica-Oblique",
        "helvetica-boldoblique" => "Helvetica-BoldOblique",
        "times"                 => "Times-Roman",
        "times-roman"           => "Times-Roman",
        "times-bold"            => "Times-Bold",
        "times-italic"          => "Times-Italic",
        "times-bolditalic"      => "Times-BoldItalic",
        "symbol"                => "Symbol",
        "zapfdinbats"           => "ZapfDingbats",
        "zapfdingbats"          => "ZapfDingbats",
    ];

    /**
     * @var \Dompdf\Dompdf
     */
    private $_dompdf;

    /**
     * Instance of PDFLib class
     *
     * @var \PDFLib
     */
    private $_pdf;

    /**
     * Name of temporary file used for PDFs created on disk
     *
     * @var string
     */
    private $_file;

    /**
     * PDF width, in points
     *
     * @var float
     */
    private $_width;

    /**
     * PDF height, in points
     *
     * @var float
     */
    private $_height;

    /**
     * Last fill color used
     *
     * @var array
     */
    private $_last_fill_color;

    /**
     * Last stroke color used
     *
     * @var array
     */
    private $_last_stroke_color;

    /**
     * The current opacity level
     *
     * @var float|null
     */
    private $_current_opacity;

    /**
     * Cache of image handles
     *
     * @var array
     */
    private $_imgs;

    /**
     * Cache of font handles
     *
     * @var array
     */
    private $_fonts;

    /**
     * Cache of fontFile checks
     *
     * @var array
     */
    private $_fontsFiles;

    /**
     * List of objects (templates) to add to multiple pages
     *
     * @var array
     */
    private $_objs;

    /**
     * List of gstate objects created for this PDF (for reuse)
     *
     * @var array
     */
    private $_gstates = array();

    /**
     * Current page number
     *
     * @var int
     */
    private $_page_number;

    /**
     * Total number of pages
     *
     * @var int
     */
    private $_page_count;

    /**
     * Array of pages for accessing after rendering is initially complete
     *
     * @var array
     */
    private $_pages;

    public function __construct($paper = "letter", string $orientation = "portrait", ?Dompdf $dompdf = null)
    {
        if (is_array($paper)) {
            $size = array_map("floatval", $paper);
        } else {
            $paper = strtolower($paper);
            $size = self::$PAPER_SIZES[$paper] ?? self::$PAPER_SIZES["letter"];
        }

        if (strtolower($orientation) === "landscape") {
            [$size[2], $size[3]] = [$size[3], $size[2]];
        }

        $this->_width = $size[2] - $size[0];
        $this->_height = $size[3] - $size[1];

        if ($dompdf === null) {
            $this->_dompdf = new Dompdf();
        } else {
            $this->_dompdf = $dompdf;
        }
        $options = $dompdf->getOptions();

//         $this->_pdf = new \PDFLib();
//         radix change for multiple dompdf obj issue
        if(!DOMPDF::$PDFLIB) {
            $this->_pdf = parent::getPDFLibObject();
            DOMPDF::$PDFLIB = $this->_pdf;
        } else {
            $this->_pdf = DOMPDF::$PDFLIB;
        }

        if ($this->getPDFLibMajorVersion() < 10) {
            $this->setPDFLibParameter("textformat", "utf8");
        } else {
            $this->setPDFLibParameter("stringformat", "utf8");
        }
//         $license = $dompdf->getOptions()->getPdflibLicense();
//         if (strlen($license) > 0) {
//             $this->setPDFLibParameter("license", $license);
//         }

        //Radix
        //$this->setPDFLibParameter("textformat", "utf8");
        $this->setPDFLibParameter("stringformat", "utf8");
        
        if ($this->getPDFLibMajorVersion() >= 7) {
            $this->setPDFLibParameter("errorpolicy", "return");
            //            $this->_pdf->set_option('logging={filename=' . \APP_PATH . '/logs/pdflib.log classes={api=1 warning=2}}');
            //            $this->_pdf->set_option('errorpolicy=exception');
        } else {
            $this->setPDFLibParameter("fontwarning", "false");
        }

        $searchPath = [$options->getFontDir(), $options->getRootDir() . "/lib/fonts"];
        if (empty($searchPath) === false) {
            $this->_pdf->set_option('searchpath={{' . implode("} {", $searchPath) . '}}');
        }

        // fetch PDFLib version information for the producer field
        $this->_pdf->set_info("Producer Addendum", sprintf("%s + PDFLib %s", $dompdf->version, $this->getPDFLibMajorVersion()));

        // Silence pedantic warnings about missing TZ settings
        $tz = @date_default_timezone_get();
        date_default_timezone_set("UTC");
        $this->_pdf->set_info("Date", date("Y-m-d"));
        date_default_timezone_set($tz);

        if (self::$IN_MEMORY) {
            $this->_pdf->begin_document("", "");
        } else {
            $tmp_dir = $options->getTempDir();
            $tmp_name = @tempnam($tmp_dir, "libdompdf_pdf_");
            @unlink($tmp_name);
            $this->_file = "$tmp_name.pdf";
            $this->_pdf->begin_document($this->_file, "");
        }

        $this->_pdf->begin_page_ext($this->_width, $this->_height, "");

        $this->_page_number = $this->_page_count = 1;
        $this->_page_text = array();

        $this->_imgs = array();
        $this->_fonts = array();
        $this->_objs = array();
    }

    function get_dompdf()
    {
        return $this->_dompdf;
    }

    /**
     * Close the pdf
     */
    protected function _close()
    {
        $this->_place_objects();

        // Close all pages
        $this->_pdf->suspend_page("");
        for ($p = 1; $p <= $this->_page_count; $p++) {
            $this->_pdf->resume_page("pagenumber=$p");
            $this->_pdf->end_page_ext("");
        }

        $this->_pdf->end_document("");
    }


    /**
     * Returns the PDFLib instance
     *
     * @return PDFLib
     */
    public function get_pdflib()
    {
        return $this->_pdf;
    }

    public function add_info(string $label, string $value): void
    {
        $this->_pdf->set_info($label, $value);
    }

    /**
     * Opens a new 'object' (template in PDFLib-speak)
     *
     * While an object is open, all drawing actions are recorded to the
     * object instead of being drawn on the current page.  Objects can
     * be added later to a specific page or to several pages.
     *
     * The return value is an integer ID for the new object.
     *
     * @see PDFLib::close_object()
     * @see PDFLib::add_object()
     *
     * @return int
     */
    public function open_object()
    {
        $this->_pdf->suspend_page("");
        if ($this->getPDFLibMajorVersion() >= 7) {
            $ret = $this->_pdf->begin_template_ext($this->_width, $this->_height, "");
        } else {
            $ret = $this->_pdf->begin_template($this->_width, $this->_height);
        }
        $this->_pdf->save();
        $this->_objs[$ret] = array("start_page" => $this->_page_number);

        return $ret;
    }

    /**
     * Reopen an existing object (NOT IMPLEMENTED)
     * PDFLib does not seem to support reopening templates.
     *
     * @param int $object the ID of a previously opened object
     *
     * @throws Exception
     */
    public function reopen_object($object)
    {
        throw new Exception("PDFLib does not support reopening objects.");
    }

    /**
     * Close the current template
     *
     * @see PDFLib::open_object()
     */
    public function close_object()
    {
        $this->_pdf->restore();
        $this->_pdf->end_template_ext();
        $this->_pdf->resume_page("pagenumber=" . $this->_page_number);
    }

    /**
     * Adds the specified object to the document
     *
     * $where can be one of:
     * - 'add' add to current page only
     * - 'all' add to every page from the current one onwards
     * - 'odd' add to all odd numbered pages from now on
     * - 'even' add to all even numbered pages from now on
     * - 'next' add the object to the next page only
     * - 'nextodd' add to all odd numbered pages from the next one
     * - 'nexteven' add to all even numbered pages from the next one
     *
     * @param int    $object the object handle returned by open_object()
     * @param string $where
     */
    public function add_object($object, $where = 'all')
    {

        if (mb_strpos($where, "next") !== false) {
            $this->_objs[$object]["start_page"]++;
            $where = str_replace("next", "", $where);
            if ($where == "") {
                $where = "add";
            }
        }

        $this->_objs[$object]["where"] = $where;
    }

    /**
     * Stops the specified template from appearing in the document.
     *
     * The object will stop being displayed on the page following the
     * current one.
     *
     * @param int $object
     */
    public function stop_object($object)
    {

        if (!isset($this->_objs[$object])) {
            return;
        }

        $start = $this->_objs[$object]["start_page"];
        $where = $this->_objs[$object]["where"];

        // Place the object on this page if required
        if ($this->_page_number >= $start &&
            (($this->_page_number % 2 == 0 && $where === "even") ||
                ($this->_page_number % 2 == 1 && $where === "odd") ||
                ($where === "all"))
        ) {
            $this->_pdf->fit_image($object, 0, 0, "");
        }

        $this->_objs[$object] = null;
        unset($this->_objs[$object]);
    }

    /**
     * Add all active objects to the current page
     */
    protected function _place_objects()
    {

        foreach ($this->_objs as $obj => $props) {
            $start = $props["start_page"];
            $where = $props["where"];

            // Place the object on this page if required
            if ($this->_page_number >= $start &&
                (($this->_page_number % 2 == 0 && $where === "even") ||
                    ($this->_page_number % 2 == 1 && $where === "odd") ||
                    ($where === "all"))
            ) {
                $this->_pdf->fit_image($obj, 0, 0, "");
            }
        }

    }

    public function get_width()
    {
        return $this->_width;
    }

    public function get_height()
    {
        return $this->_height;
    }

    public function get_page_number()
    {
        return $this->_page_number;
    }

    public function get_page_count()
    {
        return $this->_page_count;
    }

    /**
     * @param $num
     */
    public function set_page_number($num)
    {
        $this->_page_number = (int)$num;
    }

    public function set_page_count($count)
    {
        $this->_page_count = (int)$count;
    }

    /**
     * Sets the line style
     *
     * @param float  $width
     * @param string $cap
     * @param string $join
     * @param array  $dash
     */
    protected function _set_line_style($width, $cap, $join, $dash)
    {
        if (!is_array($dash)) {
            $dash = [];
        }

        // Work around PDFLib limitation with 0 dash length:
        // Value 0 for option 'dasharray' is too small (minimum 1.5e-05)
        foreach ($dash as &$d) {
            if ($d == 0) {
                $d = 1.5e-5;
            }
        }

        if (count($dash) === 1) {
            $dash[] = $dash[0];
        }

        if ($this->getPDFLibMajorVersion() >= 9) {
            if (count($dash) > 1) {
                $this->_pdf->set_graphics_option("dasharray={" . implode(" ", $dash) . "}");
            } else {
                $this->_pdf->set_graphics_option("dasharray=none");
            }
        } else {
            if (count($dash) > 1) {
                $this->_pdf->setdashpattern("dasharray={" . implode(" ", $dash) . "}");
            } else {
                $this->_pdf->setdash(0, 0);
            }
        }

        switch ($join) {
            case "miter":
                if ($this->getPDFLibMajorVersion() >= 9) {
                    $this->_pdf->set_graphics_option('linejoin=0');
                } else {
                    $this->_pdf->setlinejoin(0);
                }
                break;

            case "round":
                if ($this->getPDFLibMajorVersion() >= 9) {
                    $this->_pdf->set_graphics_option('linejoin=1');
                } else {
                    $this->_pdf->setlinejoin(1);
                }
                break;

            case "bevel":
                if ($this->getPDFLibMajorVersion() >= 9) {
                    $this->_pdf->set_graphics_option('linejoin=2');
                } else {
                    $this->_pdf->setlinejoin(2);
                }
                break;

            default:
                break;
        }

        switch ($cap) {
            case "butt":
                if ($this->getPDFLibMajorVersion() >= 9) {
                    $this->_pdf->set_graphics_option('linecap=0');
                } else {
                    $this->_pdf->setlinecap(0);
                }
                break;

            case "round":
                if ($this->getPDFLibMajorVersion() >= 9) {
                    $this->_pdf->set_graphics_option('linecap=1');
                } else {
                    $this->_pdf->setlinecap(1);
                }
                break;

            case "square":
                if ($this->getPDFLibMajorVersion() >= 9) {
                    $this->_pdf->set_graphics_option('linecap=2');
                } else {
                    $this->_pdf->setlinecap(2);
                }
                break;

            default:
                break;
        }

        $this->_pdf->setlinewidth($width);
    }

    /**
     * Sets the line color
     *
     * @param array $color array(r,g,b)
     */
    protected function _set_stroke_color($color)
    {
        if ($this->_last_stroke_color == $color) {
            //return;
        }

        $alpha = isset($color["alpha"]) ? $color["alpha"] : 1;
        if (isset($this->_current_opacity)) {
            $alpha *= $this->_current_opacity;
        }

        $this->_last_stroke_color = $color;

        if (isset($color[3])) {
            $type = "cmyk";
            list($c1, $c2, $c3, $c4) = array($color[0], $color[1], $color[2], $color[3]);
        } elseif (isset($color[2])) {
            $type = "rgb";
            list($c1, $c2, $c3, $c4) = [$color[0], $color[1], $color[2], 0];
        } else {
            $type = "gray";
            list($c1, $c2, $c3, $c4) = [$color[0], $color[1], 0, 0];
        }

        $this->_set_stroke_opacity($alpha);
        $this->_pdf->setcolor("stroke", $type, $c1, $c2, $c3, $c4);
    }

    /**
     * Sets the fill color
     *
     * @param array $color array(r,g,b)
     */
    protected function _set_fill_color($color)
    {
        if ($this->_last_fill_color == $color) {
            return;
        }

        $alpha = isset($color["alpha"]) ? $color["alpha"] : 1;
        if (isset($this->_current_opacity)) {
            $alpha *= $this->_current_opacity;
        }

        $this->_last_fill_color = $color;

        if (isset($color[3])) {
            $type = "cmyk";
            list($c1, $c2, $c3, $c4) = array($color[0], $color[1], $color[2], $color[3]);
        } elseif (isset($color[2])) {
            $type = "rgb";
            list($c1, $c2, $c3, $c4) = [$color[0], $color[1], $color[2], 0];
        } else {
            $type = "gray";
            list($c1, $c2, $c3, $c4) = [$color[0], $color[1], 0, 0];
        }

        $this->_set_fill_opacity($alpha);
        $this->_pdf->setcolor("fill", $type, $c1, $c2, $c3, $c4);
    }

    /**
     * Sets the fill opacity
     *
     * @param float  $opacity
     * @param string $mode
     */
    public function _set_fill_opacity($opacity, $mode = "Normal")
    {
        if ($mode === "Normal" && isset($opacity)) {
            $this->_set_gstate("opacityfill=$opacity");
        }
    }

    /**
     * Sets the stroke opacity
     *
     * @param float  $opacity
     * @param string $mode
     */
    public function _set_stroke_opacity($opacity, $mode = "Normal")
    {
        if ($mode === "Normal" && isset($opacity)) {
            $this->_set_gstate("opacitystroke=$opacity");
        }
    }

    public function set_opacity(float $opacity, string $mode = "Normal"): void
    {
        if ($mode === "Normal") {
            $this->_set_gstate("opacityfill=$opacity opacitystroke=$opacity");
            $this->_current_opacity = $opacity;
        }
    }

    /**
     * Sets the gstate
     *
     * @param $gstate_options
     * @return int
     */
    public function _set_gstate($gstate_options)
    {
        if (($gstate = array_search($gstate_options, $this->_gstates)) === false) {
            $gstate = $this->_pdf->create_gstate($gstate_options);
            $this->_gstates[$gstate] = $gstate_options;
        }

        return $this->_pdf->set_gstate($gstate);
    }

    public function set_default_view($view, $options = array())
    {
        // TODO
        // http://www.pdflib.com/fileadmin/pdflib/pdf/manuals/PDFlib-8.0.2-API-reference.pdf
        /**
         * fitheight Fit the page height to the window, with the x coordinate left at the left edge of the window.
         * fitrect Fit the rectangle specified by left, bottom, right, and top to the window.
         * fitvisible Fit the visible contents of the page (the ArtBox) to the window.
         * fitvisibleheight Fit the visible contents of the page to the window with the x coordinate left at the left edge of the window.
         * fitvisiblewidth Fit the visible contents of the page to the window with the y coordinate top at the top edge of the window.
         * fitwidth Fit the page width to the window, with the y coordinate top at the top edge of the window.
         * fitwindow Fit the complete page to the window.
         * fixed
         */
        //$this->setPDFLibParameter("openaction", $view);
    }

    /**
     * Loads a specific font and stores the corresponding descriptor.
     *
     * @param string $font
     * @param string $encoding
     * @param string $options
     *
     * @return int the font descriptor for the font
     */
    protected function _load_font($font, $encoding = null, $options = "")
    {
        // Fix for PDFLib's case-sensitive font names
        $baseFont = basename($font);
        $isNativeFont = false;
        $lcBaseFont = strtolower($baseFont);
        if (isset(self::$nativeFontsToPDFLib[$lcBaseFont])) {
            $baseFont = self::$nativeFontsToPDFLib[$lcBaseFont];
            $isNativeFont = true;
        }

        // Embed non-native fonts
        $test = strtolower($baseFont);
        
        // Radix
        $pdflib_911_version_fix = "";
        if (($this->_pdf->get_option("major", "")*100)+($this->_pdf->get_option("minor", "")*10)+($this->_pdf->get_option("revision", "")) > "910") {
            // $pdflib_911_version_fix = ' oldsubsetting';
        }
        $options .= $pdflib_911_version_fix . " ";
        
        if (in_array($test, DOMPDF::$nativeFonts)) {
            $font = basename($font);
        } else {
            // Embed non-native fonts
            $options .= " embedding=true";
        }
        $font = $lcBaseFont;
        $options .= " autosubsetting=" . ($this->_dompdf->getOptions()->getIsFontSubsettingEnabled() === false ? "false" : "true");

        if (is_null($encoding)) {
            // Unicode encoding is only available for the commerical
            // version of PDFlib and not PDFlib-Lite
            if (strlen($this->_dompdf->getOptions()->getPdflibLicense()) > 0) {
                $encoding = "unicode";
            } else {
                $encoding = "auto";
            }
        }

        $key = "$font:$encoding:$options";
        if (isset($this->_fonts[$key])) {
            return $this->_fonts[$key];
        }

        // Native fonts are build in, just load it
        if ($isNativeFont) {
            $this->_fonts[$key] = $this->_pdf->load_font($baseFont, $encoding, $options);
            return $this->_fonts[$key];
        }

        $fontOutline = $this->getPDFLibParameter("FontOutline", 1);
        if ($fontOutline === "" || $fontOutline < 0) {
            $families = $this->_dompdf->getFontMetrics()->getFontFamilies();
            foreach ($families as $files) {
                foreach ($files as $file) {
                    $face = basename($file);
                    $afm = null;

                    if (isset($this->_fontsFiles[$face])) {
                        continue;
                    }

                    // Prefer ttfs to afms
                    if (file_exists("$file.ttf")) {
                        $outline = "$file.ttf";
                    } elseif (file_exists("$file.TTF")) {
                        $outline = "$file.TTF";
                    } elseif (file_exists("$file.pfb")) {
                        $outline = "$file.pfb";
                        if (file_exists("$file.afm")) {
                            $afm = "$file.afm";
                        }
                    } elseif (file_exists("$file.PFB")) {
                        $outline = "$file.PFB";
                        if (file_exists("$file.AFM")) {
                            $afm = "$file.AFM";
                        }
                    } else {
                        continue;
                    }

                    $this->_fontsFiles[$face] = true;

                    if ($this->getPDFLibMajorVersion() >= 9) {
                        $this->setPDFLibParameter("FontOutline", '{' . "$face=$outline" . '}');
                    } else {
                        $this->setPDFLibParameter("FontOutline", "\{$face\}=\{$outline\}");
                    }

                    if (is_null($afm)) {
                        continue;
                    }
                    if ($this->getPDFLibMajorVersion() >= 9) {
                        $this->setPDFLibParameter("FontAFM", '{' . "$face=$afm" . '}');
                    } else {
                        $this->setPDFLibParameter("FontAFM", "\{$face\}=\{$afm\}");
                    }
                }
            }
        }

        $this->_fonts[$key] = $this->_pdf->load_font($baseFont, $encoding, $options);
        return $this->_fonts[$key];
    }

    /**
     * Remaps y coords from 4th to 1st quadrant
     *
     * @param float $y
     * @return float
     */
    protected function y($y)
    {
        return $this->_height - $y;
    }

    public function line($x1, $y1, $x2, $y2, $color, $width, $style = [], $cap = "butt")
    {
        $this->_set_line_style($width, $cap, "", $style);
        $this->_set_stroke_color($color);

        $y1 = $this->y($y1);
        $y2 = $this->y($y2);

        $this->_pdf->moveto($x1, $y1);
        $this->_pdf->lineto($x2, $y2);
        $this->_pdf->stroke();

        $this->_set_stroke_opacity($this->_current_opacity, "Normal");
    }

    public function arc($x, $y, $r1, $r2, $astart, $aend, $color, $width, $style = [], $cap = "butt")
    {
        $this->_set_line_style($width, $cap, "", $style);
        $this->_set_stroke_color($color);

        $y = $this->y($y);

        $this->_pdf->arc($x, $y, $r1, $astart, $aend);
        $this->_pdf->stroke();

        $this->_set_stroke_opacity($this->_current_opacity, "Normal");
    }

    public function rectangle($x1, $y1, $w, $h, $color, $width, $style = [], $cap = "butt")
    {
        $this->_set_stroke_color($color);
        $this->_set_line_style($width, $cap, "", $style);

        $y1 = $this->y($y1) - $h;

        $this->_pdf->rect($x1, $y1, $w, $h);
        $this->_pdf->stroke();

        $this->_set_stroke_opacity($this->_current_opacity, "Normal");
    }

    public function filled_rectangle($x1, $y1, $w, $h, $color)
    {
        $this->_set_fill_color($color);

        $y1 = $this->y($y1) - $h;

        $this->_pdf->rect(floatval($x1), floatval($y1), floatval($w), floatval($h));
        $this->_pdf->fill();

        $this->_set_fill_opacity($this->_current_opacity, "Normal");
    }

    public function clipping_rectangle($x1, $y1, $w, $h)
    {
        $this->_pdf->save();

        $y1 = $this->y($y1) - $h;

        $this->_pdf->rect(floatval($x1), floatval($y1), floatval($w), floatval($h));
        $this->_pdf->clip();
    }

    public function clipping_roundrectangle($x1, $y1, $w, $h, $rTL, $rTR, $rBR, $rBL)
    {
        if ($this->getPDFLibMajorVersion() < 9) {
            $this->clipping_rectangle($x1, $y1, $w, $h);
            return;
        }

        $this->_pdf->save();

        // we use 0,0 for the base coordinates for the path points
        // since we're drawing the path at the $x1,$y1 coordinates

        $path = 0;
        //start: left edge, top end
        $path = $this->_pdf->add_path_point($path, 0, 0 - $rTL + $h, "move", "");
        // line: left edge, bottom end
        $path = $this->_pdf->add_path_point($path, 0, 0 + $rBL, "line", "");
        // curve: bottom-left corner
        if ($rBL > 0) {
            $path = $this->_pdf->add_path_point($path, 0 + $rBL, 0, "elliptical", "radius=$rBL clockwise=false");
        }
        // line: bottom edge, left end
        $path = $this->_pdf->add_path_point($path, 0 - $rBR + $w, 0, "line", "");
        // curve: bottom-right corner
        if ($rBR > 0) {
            $path = $this->_pdf->add_path_point($path, 0 + $w, 0 + $rBR, "elliptical", "radius=$rBR clockwise=false");
        }
        // line: right edge, top end
        $path = $this->_pdf->add_path_point($path, 0 + $w, 0 - $rTR + $h, "line", "");
        // curve: top-right corner
        if ($rTR > 0) {
            $path = $this->_pdf->add_path_point($path, 0 - $rTR + $w, 0 + $h, "elliptical", "radius=$rTR clockwise=false");
        }
        // line: top edge, left end
        $path = $this->_pdf->add_path_point($path, 0 + $rTL, 0 + $h, "line", "");
        // curve: top-left corner
        if ($rTL > 0) {
            $path = $this->_pdf->add_path_point($path, 0, 0 - $rTL + $h, "elliptical", "radius=$rTL clockwise=false");
        }
        $this->_pdf->draw_path($path, $x1, $this->_height-$y1-$h, "clip=true");
    }

    public function clipping_polygon(array $points): void
    {
        $this->_pdf->save();

        $y = $this->y(array_pop($points));
        $x = array_pop($points);
        $this->_pdf->moveto($x, $y);

        while (count($points) > 1) {
            $y = $this->y(array_pop($points));
            $x = array_pop($points);
            $this->_pdf->lineto($x, $y);
        }

        $this->_pdf->closepath();
        $this->_pdf->clip();
    }

    public function clipping_end()
    {
        $this->_pdf->restore();
    }

    public function save()
    {
        $this->_pdf->save();
    }

    function restore()
    {
        $this->_pdf->restore();
    }

    public function rotate($angle, $x, $y)
    {
        $pdf = $this->_pdf;
        $pdf->translate($x, $this->_height - $y);
        $pdf->rotate(-$angle);
        $pdf->translate(-$x, -$this->_height + $y);
    }

    public function skew($angle_x, $angle_y, $x, $y)
    {
        $pdf = $this->_pdf;
        $pdf->translate($x, $this->_height - $y);
        $pdf->skew($angle_y, $angle_x); // Needs to be inverted
        $pdf->translate(-$x, -$this->_height + $y);
    }

    public function scale($s_x, $s_y, $x, $y)
    {
        $pdf = $this->_pdf;
        $pdf->translate($x, $this->_height - $y);
        $pdf->scale($s_x, $s_y);
        $pdf->translate(-$x, -$this->_height + $y);
    }

    public function translate($t_x, $t_y)
    {
        $this->_pdf->translate($t_x, -$t_y);
    }

    public function transform($a, $b, $c, $d, $e, $f)
    {
        $this->_pdf->concat($a, $b, $c, $d, $e, $f);
    }

    public function polygon($points, $color, $width = null, $style = [], $fill = false)
    {
        $this->_set_fill_color($color);
        $this->_set_stroke_color($color);

        if (!$fill && isset($width)) {
            $this->_set_line_style($width, "square", "miter", $style);
        }

        $y = $this->y(array_pop($points));
        $x = array_pop($points);
        $this->_pdf->moveto($x, $y);

        while (count($points) > 1) {
            $y = $this->y(array_pop($points));
            $x = array_pop($points);
            $this->_pdf->lineto($x, $y);
        }

        if ($fill) {
            $this->_pdf->fill();
        } else {
            $this->_pdf->closepath_stroke();
        }

        $this->_set_fill_opacity($this->_current_opacity, "Normal");
        $this->_set_stroke_opacity($this->_current_opacity, "Normal");
    }

    public function circle($x, $y, $r, $color, $width = null, $style = [], $fill = false)
    {
        $this->_set_fill_color($color);
        $this->_set_stroke_color($color);

        if (!$fill && isset($width)) {
            $this->_set_line_style($width, "round", "round", $style);
        }

        $y = $this->y($y);

        $this->_pdf->circle($x, $y, $r);

        if ($fill) {
            $this->_pdf->fill();
        } else {
            $this->_pdf->stroke();
        }

        $this->_set_fill_opacity($this->_current_opacity, "Normal");
        $this->_set_stroke_opacity($this->_current_opacity, "Normal");
    }

    /**
     * Convert image to a PNG image
     *
     * @param string $image_url
     * @param string $type
     *
     * @return string|null The url of the newly converted image
     */
    protected function _convert_to_png($image_url, $type)
    {
        $filename = Cache::getTempImage($image_url);

        if ($filename !== null && file_exists($filename)) {
            return $filename;
        }
 
        $func_name = "imagecreatefrom$type";

        set_error_handler([Helpers::class, "record_warnings"]);

        if (method_exists(Helpers::class, $func_name)) {
            $func_name = [Helpers::class, $func_name];
        } elseif (!function_exists($func_name)) {
            throw new Exception("Function $func_name() not found.  Cannot convert $type image: $image_url.  Please install the image PHP extension.");
        }

        try {
            $im = call_user_func($func_name, $image_url);

            if ($im) {
                imageinterlace($im, false);

                $tmp_dir = $this->_dompdf->getOptions()->getTempDir();
                $tmp_name = @tempnam($tmp_dir, "{$type}_dompdf_img_");
                @unlink($tmp_name);
                $filename = "$tmp_name.png";

                imagepng($im, $filename);
                imagedestroy($im);
            } else {
                $filename = null;
            }
        } finally {
            restore_error_handler();
        }

        if ($filename !== null) {
            Cache::addTempImage($image_url, $filename);
        }

        return $filename;
    }

    public function image($img, $x, $y, $w, $h, $resolution = "normal")
    {
        $w = (int)$w;
        $h = (int)$h;

        $img_type = Cache::detect_type($img, $this->get_dompdf()->getHttpContext());

        // Strip file:// prefix
        if (substr($img, 0, 7) === "file://") {
            $img = substr($img, 7);
        }

        if (!isset($this->_imgs[$img])) {
            switch (strtolower($img_type)) {
                case "webp":
                    $img = $this->_convert_to_png($img, $img_type);
                    if ($img === null) {
                        $img = Cache::$broken_image;
                    }
                    $this->image($img, $x, $y, $w, $h, $resolution);
                    return;
                case "gif":
                    if ($this->getPDFLibMajorVersion() >= 10) {
                        $img = $this->_convert_to_png($img, $img_type);
                        if ($img === null) {
                            $img = Cache::$broken_image;
                        }
                        $this->image($img, $x, $y, $w, $h, $resolution);
                        return;
                    }
                case "bmp":
                /** @noinspection PhpMissingBreakStatementInspection */
                case "jpeg":
                /** @noinspection PhpMissingBreakStatementInspection */
                case "png":
                    $image_load_response = $this->_pdf->load_image($img_type, $img, "");
                    break;
                case "svg":
                    $image_load_response = $this->_pdf->load_graphics($img_type, $img, "");
                    break;
                default:
                    // not handled
                    $this->image(Cache::$broken_image, $x, $y, $w, $h, $resolution);
                    return;
            }
            if ($image_load_response === 0) {
                //TODO: should do something with the error message
                $error = $this->_pdf->get_errmsg();
                return;
            }
            $this->_imgs[$img] = $image_load_response;
        }

        $img = $this->_imgs[$img];

        $y = $this->y($y) - $h;
        $this->_pdf->fit_image($img, $x, $y, 'boxsize={' . "$w $h" . '} fitmethod=entire');
    }

    public function text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_spacing = 0, $char_spacing = 0, $angle = 0)
    {
        //Radix change - To add barcode in invoice pdf
        if (preg_match("/^barcode:/", $text)){
            $text_data = explode("barcode:",$text);
            $text = "*".$text_data[1]."*";
            $font = $_SERVER['DOCUMENT_ROOT'] . "/lib/fonts/FRE3OF9X";
            $size = 40;
        }
        
        if ($size == 0) {
            return;
        }

        $fh = $this->_load_font($font);

        $this->_pdf->setfont($fh, $size);
        $this->_set_fill_color($color);

        $y = $this->y($y) - $this->get_font_height($font, $size);

        $word_spacing = (float)$word_spacing;
        $char_spacing = (float)$char_spacing;
        $angle = -(float)$angle;
        
        $this->_pdf->fit_textline($text, $x, $y, "rotate=$angle wordspacing=$word_spacing charspacing=$char_spacing ");

        $this->_set_fill_opacity($this->_current_opacity, "Normal");
    }

    public function javascript($code)
    {
        if (strlen($this->_dompdf->getOptions()->getPdflibLicense()) > 0) {
            $this->_pdf->create_action("JavaScript", $code);
        }
    }

    public function add_named_dest($anchorname)
    {
        $this->_pdf->add_nameddest($anchorname, "");
    }

    public function add_link($url, $x, $y, $width, $height)
    {
        $y = $this->y($y) - $height;
        if (strpos($url, '#') === 0) {
            // Local link
            $name = substr($url, 1);
            if ($name) {
                $this->_pdf->create_annotation($x, $y, $x + $width, $y + $height, 'Link',
                    "contents={$url} destname=" . substr($url, 1) . " linewidth=0");
            }
        } else {
            //TODO: PDFLib::create_action does not permit non-HTTP links for URI actions
            $action = $this->_pdf->create_action("URI", "url={{$url}}");
            // add the annotation only if the action was created
            if ($action !== 0) {
                $this->_pdf->create_annotation($x, $y, $x + $width, $y + $height, 'Link', "contents={{$url}} action={activate=$action} linewidth=0");
            }
        }
    }

    public function font_supports_char(string $font, string $char): bool
    {
        if ($char === "") {
            return true;
        }

        $fh = $this->_load_font($font);
        if ($fh === 0) {
            return false;
        }
        $this->_pdf->setfont($fh, 10);

        // unicode character glyph id lookup supports both the character and the unicode ordinal value
        // because some characters can not be specified directly we'll specify the ordinal for all characters
        // known problematic characters: "{", "}", " ", "=", "\u{feff}"
        $char_code = Helpers::uniord($char, "UTF-8");
        $options = "unicode=$char_code";
        $glyphid = (int) $this->_pdf->info_font($fh, "glyphid", $options);

        return $glyphid !== -1;
    }

    public function get_text_width($text, $font, $size, $word_spacing = 0.0, $letter_spacing = 0.0)
    {
        if ($size == 0) {
            return 0.0;
        }

        $fh = $this->_load_font($font);

        // Determine the additional width due to extra spacing
        $num_spaces = mb_substr_count($text, " ");
        $delta = $word_spacing * $num_spaces;

        if ($letter_spacing) {
            $num_chars = mb_strlen($text);
            $delta += $num_chars * $letter_spacing;
        }

        return $this->_pdf->stringwidth($text, $fh, $size) + $delta;
    }

    public function get_font_height($font, $size)
    {
        if ($size == 0) {
            return 0.0;
        }

        $fh = $this->_load_font($font);

        $this->_pdf->setfont($fh, $size);

        $asc = $this->_pdf->info_font($fh, "ascender", "fontsize=1");
        $desc = $this->_pdf->info_font($fh, "descender", "fontsize=1");
        
        // $desc is usually < 0,
        $ratio = $this->_dompdf->getOptions()->getFontHeightRatio();

        return $size * ($asc - $desc) * $ratio;
    }

    public function get_font_baseline($font, $size)
    {
        $ratio = $this->_dompdf->getOptions()->getFontHeightRatio();

        return $this->get_font_height($font, $size) / $ratio * 1.1;
    }

    /**
     * Processes a callback or script on every page.
     *
     * The callback function receives the four parameters `int $pageNumber`,
     * `int $pageCount`, `Canvas $canvas`, and `FontMetrics $fontMetrics`, in
     * that order. If a script is passed as string, the variables `$PAGE_NUM`,
     * `$PAGE_COUNT`, `$pdf`, and `$fontMetrics` are available instead. Passing
     * a script as string is deprecated and will be removed in a future version.
     *
     * This function can be used to add page numbers to all pages after the
     * first one, for example.
     *
     * @param callable|string $callback The callback function or PHP script to process on every page
     */
    public function page_script($callback): void
    {
        if (is_string($callback)) {
            $this->processPageScript(function (
                int $PAGE_NUM,
                int $PAGE_COUNT,
                self $pdf,
                FontMetrics $fontMetrics
            ) use ($callback) {
                eval($callback);
            });
            return;
        }

        $this->processPageScript($callback);
    }

    public function page_text($x, $y, $text, $font, $size, $color = [0, 0, 0], $word_space = 0.0, $char_space = 0.0, $angle = 0.0)
    {
        $this->processPageScript(function (int $pageNumber, int $pageCount) use ($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle) {
            $text = str_replace(
                ["{PAGE_NUM}", "{PAGE_COUNT}"],
                [$pageNumber, $pageCount],
                $text
            );
            $this->text($x, $y, $text, $font, $size, $color, $word_space, $char_space, $angle);
        });
    }

    public function page_line($x1, $y1, $x2, $y2, $color, $width, $style = [])
    {
        $this->processPageScript(function () use ($x1, $y1, $x2, $y2, $color, $width, $style) {
            $this->line($x1, $y1, $x2, $y2, $color, $width, $style);
        });
    }

    public function new_page()
    {
        // Add objects to the current page
        $this->_place_objects();

        $this->_pdf->suspend_page("");
        $this->_pdf->begin_page_ext($this->_width, $this->_height, "");
        $this->_page_number = ++$this->_page_count;
    }

    protected function processPageScript(callable $callback): void
    {
        $this->_pdf->suspend_page("");

        for ($p = 1; $p <= $this->_page_count; $p++) {
            $this->_pdf->resume_page("pagenumber=$p");

            $fontMetrics = $this->_dompdf->getFontMetrics();
            $callback($p, $this->_page_count, $this, $fontMetrics);

            $this->_pdf->suspend_page("");
        }

        $this->_pdf->resume_page("pagenumber=" . $this->_page_number);
    }

    /**
     * @throws Exception
     */
    public function stream($filename = "document.pdf", $options = array())
    {
        if (headers_sent()) {
            die("Unable to stream pdf: headers already sent");
        }

        if (!isset($options["compress"])) {
            $options["compress"] = true;
        }
        if (!isset($options["Attachment"])) {
            $options["Attachment"] = true;
        }

        if ($options["compress"]) {
            $this->setPDFLibValue("compress", 6);
        } else {
            $this->setPDFLibValue("compress", 0);
        }

        $this->_close();

        $data = "";

        if (self::$IN_MEMORY) {
            $data = $this->_pdf->get_buffer();
            $size = mb_strlen($data, "8bit");
        } else {
            $size = filesize($this->_file);
        }

        header("Cache-Control: private");
        header("Content-Type: application/pdf");
        header("Content-Length: " . $size);

        $filename = str_replace(array("\n", "'"), "", basename($filename, ".pdf")) . ".pdf";
        $attachment = $options["Attachment"] ? "attachment" : "inline";
        header(Helpers::buildContentDispositionHeader($attachment, $filename));

        if (self::$IN_MEMORY) {
            echo $data;
        } else {
            // Chunked readfile()
            $chunk = (1 << 21); // 2 MB
            $fh = fopen($this->_file, "rb");
            if (!$fh) {
                throw new Exception("Unable to load temporary PDF file: " . $this->_file);
            }

            while (!feof($fh)) {
                echo fread($fh, $chunk);
            }
            fclose($fh);

            //debugpng
            if ($this->_dompdf->getOptions()->getDebugPng()) {
                print '[pdflib stream unlink ' . $this->_file . ']';
            }
            if (!$this->_dompdf->getOptions()->getDebugKeepTemp()) {
                unlink($this->_file);
            }
            $this->_file = null;
            unset($this->_file);
        }

        flush();
    }

    public function output($options = [])
    {
        if (!isset($options["compress"])) {
            $options["compress"] = true;
        }

        if ($options["compress"]) {
            $this->setPDFLibValue("compress", 6);
        } else {
            $this->setPDFLibValue("compress", 0);
        }

        $this->_close();

        if (self::$IN_MEMORY) {
            $data = $this->_pdf->get_buffer();
        } else {
            $data = file_get_contents($this->_file);

            //debugpng
            if ($this->_dompdf->getOptions()->getDebugPng()) {
                print '[pdflib output unlink ' . $this->_file . ']';
            }
            if (!$this->_dompdf->getOptions()->getDebugKeepTemp()) {
                unlink($this->_file);
            }
            $this->_file = null;
            unset($this->_file);
        }

        return $data;
    }
    
    public function close()
    {
    	$this->_close();
    }

    /**
     * @param string $keyword
     * @param string $optlist
     * @return mixed
     */
    protected function getPDFLibParameter($keyword, $optlist = "")
    {
        if ($this->getPDFLibMajorVersion() >= 9) {
            return $this->_pdf->get_option($keyword, "");
        }

        return $this->_pdf->get_parameter($keyword, $optlist);
    }

    /**
     * @param string $keyword
     * @param string $value
     * @return mixed
     */
    protected function setPDFLibParameter($keyword, $value)
    {
        if ($this->getPDFLibMajorVersion() >= 9) {
            return $this->_pdf->set_option($keyword . "=" . $value);
        }

        return $this->_pdf->set_parameter($keyword, $value);
    }

    /**
     * @param string $keyword
     * @param string $optlist
     * @return mixed
     */
    protected function getPDFLibValue($keyword, $optlist = "")
    {
        if ($this->getPDFLibMajorVersion() >= 9) {
            return $this->getPDFLibParameter($keyword, $optlist);
        }

        return $this->_pdf->get_option($keyword);
    }

    /**
     * @param string $keyword
     * @param string $value
     * @return mixed
     */
    protected function setPDFLibValue($keyword, $value)
    {
        if ($this->getPDFLibMajorVersion() >= 9) {
            return $this->setPDFLibParameter($keyword, $value);
        }

        return $this->_pdf->set_value($keyword, $value);
    }

    /**
     * @return int
     */
    private function getPDFLibMajorVersion()
    {
        if (is_null(self::$MAJOR_VERSION)) {
            if (method_exists($this->_pdf, "get_option")) {
                self::$MAJOR_VERSION = abs(intval($this->_pdf->get_option("major", "")));
            } else {
                self::$MAJOR_VERSION = abs(intval($this->_pdf->get_value("major", "")));
            }
        }

        return self::$MAJOR_VERSION;
    }
}

// Workaround for idiotic limitation on statics...
PDFLib::$PAPER_SIZES = CPDF::$PAPER_SIZES;
