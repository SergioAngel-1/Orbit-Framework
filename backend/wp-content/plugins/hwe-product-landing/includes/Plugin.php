<?php
namespace HWE\ProductLanding;

final class Plugin {
    public static function register(): void {
        MetaBox::register();
        Save::register();
        Translations::register();
        Revalidation::register();
        Graphql::register();
    }
}
