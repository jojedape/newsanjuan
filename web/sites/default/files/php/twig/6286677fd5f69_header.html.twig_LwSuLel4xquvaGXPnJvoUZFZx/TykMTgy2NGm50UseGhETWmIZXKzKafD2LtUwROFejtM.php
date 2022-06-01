<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;

/* @bootstrap_for_drupal/includes/header.html.twig */
class __TwigTemplate_3e5c06452d33dd549b539d4f21944fe551650310be9a38f6a97b93fb788c0935 extends \Twig\Template
{
    private $source;
    private $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
        $this->sandbox = $this->env->getExtension('\Twig\Extension\SandboxExtension');
        $this->checkSecurity();
    }

    protected function doDisplay(array $context, array $blocks = [])
    {
        $macros = $this->macros;
        // line 1
        echo "<!-- HEADER -->
";
        // line 2
        if (twig_get_attribute($this->env, $this->source, ($context["page"] ?? null), "header_special", [], "any", false, false, true, 2)) {
            // line 3
            echo "  <header class=\"bg-secondary\" role=\"special-message\">
    ";
            // line 4
            echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(twig_get_attribute($this->env, $this->source, ($context["page"] ?? null), "header_special", [], "any", false, false, true, 4), 4, $this->source), "html", null, true);
            echo "
  </header>
";
        }
        // line 7
        echo "<header class=\"bg-secondary pt-3 \" role=\"banner-brand\" id=\"header-brand\">
  <div class=\"container\">

    <!-- branding header -->
    <div class=\"d-flex flex-md-column align-items-md-start\">
      <div class=\"d-md-flex\">
        <div class=\"d-none d-md-flex align-items-center\">
          <a class=\"navbar-brand d-flex text-white align-items-center\" href=\"";
        // line 14
        echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(($context["front_page"] ?? null), 14, $this->source), "html", null, true);
        echo "\"><img class=\"text-white mr-3 logo\" src=\"";
        echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(($context["logopath"] ?? null), 14, $this->source), "html", null, true);
        echo "\">";
        echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(($context["site_name"] ?? null), 14, $this->source), "html", null, true);
        echo "</a>
        </div>
        <div>
          <p class=\"d-flex align-items-center text-white h-100 pl-md-3 text-center text-md-left slogan\">";
        // line 17
        echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(($context["site_slogan"] ?? null), 17, $this->source), "html", null, true);
        echo "</p>
        </div>
      </div>
    </div>
    <!-- branding header -->

  </div>
</header>
<header class=\"sticky-top d-print-none\" role=\"header-menu\" id=\"header-menu\">
  <nav role=\"main-navigation\" class=\"navbar navbar-dark navbar-expand-md bg-secondary px-0 px-md-3 mb-2 mb-md-0\">
    <div class=\"container\">

      <!-- Sticky bar logo -->
      <div class=\"ml-3 ml-md-0\">
        <a class=\"navbar-brand mr-2 d-flex align-items-center py-0\" href=\"";
        // line 31
        echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(($context["front_page"] ?? null), 31, $this->source), "html", null, true);
        echo "\"><img class=\"navbar-brand mr-2 hide-logo logo\" src=\"";
        echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(($context["logopath"] ?? null), 31, $this->source), "html", null, true);
        echo "\"/>
          <span class=\"d-md-none\">";
        // line 32
        echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(($context["site_name"] ?? null), 32, $this->source), "html", null, true);
        echo "</span></a>
      </div>
      <!-- Sticky bar logo -->

      <!-- button collapse -->
      <button data-toggle=\"collapse\" class=\"navbar-toggler ml-auto mr-3 mr-sm-0\" data-target=\"#navigation-container\">
        <span class=\"sr-only\">Toggle navigation</span>
        <span class=\"navbar-toggler-icon\"></span>
      </button>
      <!-- button collapse -->

      <!-- navbar collapse / mobile Menu -->
      <div class=\"collapse navbar-collapse position-relative justify-content-between\" id=\"navigation-container\">

        <!-- nav_main -->
        <div id=\"menu-main\" class=\"search-effect\">
          ";
        // line 48
        echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(twig_get_attribute($this->env, $this->source, ($context["page"] ?? null), "nav_main", [], "any", false, false, true, 48), 48, $this->source), "html", null, true);
        echo "
        </div>
        <!-- nav_main -->

        <!-- nav_additional -->
        <div id=\"menu-add\">
          ";
        // line 54
        echo $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->sandbox->ensureToStringAllowed(twig_get_attribute($this->env, $this->source, ($context["page"] ?? null), "nav_add", [], "any", false, false, true, 54), 54, $this->source), "html", null, true);
        echo "
        </div>
        <!-- nav_additional -->

      </div>
      <!-- navbar collapse / mobile Menu -->

    </div>
  </nav>
</header>
<!-- HEADER -->
";
    }

    public function getTemplateName()
    {
        return "@bootstrap_for_drupal/includes/header.html.twig";
    }

    public function isTraitable()
    {
        return false;
    }

    public function getDebugInfo()
    {
        return array (  123 => 54,  114 => 48,  95 => 32,  89 => 31,  72 => 17,  62 => 14,  53 => 7,  47 => 4,  44 => 3,  42 => 2,  39 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "@bootstrap_for_drupal/includes/header.html.twig", "/var/www/html/web/themes/contrib/bfd/templates/includes/header.html.twig");
    }
    
    public function checkSecurity()
    {
        static $tags = array("if" => 2);
        static $filters = array("escape" => 4);
        static $functions = array();

        try {
            $this->sandbox->checkSecurity(
                ['if'],
                ['escape'],
                []
            );
        } catch (SecurityError $e) {
            $e->setSourceContext($this->source);

            if ($e instanceof SecurityNotAllowedTagError && isset($tags[$e->getTagName()])) {
                $e->setTemplateLine($tags[$e->getTagName()]);
            } elseif ($e instanceof SecurityNotAllowedFilterError && isset($filters[$e->getFilterName()])) {
                $e->setTemplateLine($filters[$e->getFilterName()]);
            } elseif ($e instanceof SecurityNotAllowedFunctionError && isset($functions[$e->getFunctionName()])) {
                $e->setTemplateLine($functions[$e->getFunctionName()]);
            }

            throw $e;
        }

    }
}
