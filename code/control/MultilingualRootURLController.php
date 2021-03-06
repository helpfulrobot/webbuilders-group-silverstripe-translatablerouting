<?php
class MultilingualRootURLController extends RootURLController {
    /**
     * Tells the routing controllers to use the locale url or not i.e /en_US/ instead of /en/
     * @default false
     * @config MultilingualRootURLController.UseLocaleURL
     */
    private static $UseLocaleURL=false;
    
    /**
     * Tells the routing controllers to use a dashed locale or not i.e /en-us/ instead of /en_US/ requires MultilingualRootURLController.UseLocaleURL to be true
     * @default false
     * @config MultilingualRootURLController.UseDashLocale
     */
    private static $UseDashLocale=false;
    
    public function handleRequest(SS_HTTPRequest $request, DataModel $model=null) {
        self::$is_at_root=true;
        $this->setDataModel($model);
        
        $this->pushCurrent();
        $this->init();
        
        if($language=$request->param('Language')) {
            if(Config::inst()->get('MultilingualRootURLController', 'UseLocaleURL')) {
                if(Config::inst()->get('MultilingualRootURLController', 'UseDashLocale')) {
                    //Language is missing a dash 404
                    if(strpos($language, '-')===false) {
                        //Locale not found 404
                        if($response=ErrorPage::response_for(404)) {
                            return $response;
                        }else {
                            $this->httpError(404, 'The requested page could not be found.');
                        }
                        
                        return $this->response;
                    }
                
                    $locale=explode('-', $language);
                    $locale[1]=strtoupper($locale[1]);
                    
                    //Make sure that the language is all lowercase
                    if($language==implode('-', $locale)) {
                        //Locale not found 404
                        if($response=ErrorPage::response_for(404)) {
                            return $response;
                        }else {
                            $this->httpError(404, 'The requested page could not be found.');
                        }
                        
                        return $this->response;
                    }
                    
                    $locale=implode('_', $locale);
                }else {
                    $locale=$language;
                }
            }else if(strpos($request->param('Language'), '_')!==false) {
                //Locale not found 404
                if($response=ErrorPage::response_for(404)) {
                    return $response;
                }else {
                    $this->httpError(404, 'The requested page could not be found.');
                }
                
                return $this->response;
            }else {
                $locale=i18n::get_locale_from_lang($language);
            }
            
            if(in_array($locale, Translatable::get_allowed_locales())) {
                Cookie::set('language', $language);
                
                Translatable::set_current_locale($locale);
                i18n::set_locale($locale);
                
                
                if(!DB::isActive() || !ClassInfo::hasTable('SiteTree')) {
                    $this->response=new SS_HTTPResponse();
                    $this->response->redirect(Director::absoluteBaseURL().'dev/build?returnURL='.(isset($_GET['url']) ? urlencode($_GET['url']):null));
                    return $this->response;
                }
                
                $request->setUrl($language.'/'.self::get_homepage_link().'/');
                $request->match('$Language/$URLSegment//$Action', true);
                
                
                $controller=new MultilingualModelAsController();
                $result=$controller->handleRequest($request, $model);
                
                $this->popCurrent();
                return $result;
            }else {
                //URL Param Locale is not allowed so redirect to default
                $this->redirect(Controller::join_links(Director::baseURL(), (Config::inst()->get('MultilingualRootURLController', 'UseLocaleURL') ? Translatable::default_locale():Translatable::default_lang())).'/', 301);
                
                $this->popCurrent();
                return $this->response;
            }
        }
        
        
        //No Locale Param so detect browser language and redirect
        if($locale=self::detect_browser_locale()) {
            if(Config::inst()->get('MultilingualRootURLController', 'UseLocaleURL')) {
                if(Config::inst()->get('MultilingualRootURLController', 'UseDashLocale')) {
                    $language=str_replace('_', '-', strtolower($locale));
                }else {
                    $language=$locale;
                }
            }else {
                $language=i18n::get_lang_from_locale($locale);
            }
            
            Cookie::set('language', $language);
            
            $this->redirect(Controller::join_links(Director::baseURL(), $language).'/', 301);
            
            $this->popCurrent();
            return $this->response;
        }
        
        
        if(Config::inst()->get('MultilingualRootURLController', 'UseLocaleURL')) {
            if(Config::inst()->get('MultilingualRootURLController', 'UseDashLocale')) {
                $language=str_replace('_', '-', strtolower(Translatable::default_locale()));
            }else {
                $language=Translatable::default_locale();
            }
        }else {
            $language=Translatable::default_lang();
        }
        
        $this->redirect(Controller::join_links(Director::baseURL(), $language.'/'), 301);
        
        $this->popCurrent();
        return $this->response;
    }
    
    /**
     * Determines the locale best matching the given list of browser locales
     * @return {string} The matching locale, or null if none could be determined
     */
    public static function detect_browser_locale() {
        if($language=Cookie::get('language')) {
            if(Config::inst()->get('MultilingualRootURLController', 'UseLocaleURL')) {
                $locale=$language;
            }else {
                $locale=i18n::get_locale_from_lang($language);
            }
            
            if(in_array($locale, Translatable::get_allowed_locales())) {
                return $locale;
            }else {
                Cookie::force_expiry('language');
            }
        }
        
        // Given multiple canditates, narrow down the final result using the client's preferred languages
        $inputLocales=(array_key_exists('HTTP_ACCEPT_LANGUAGE', $_SERVER) ? $_SERVER['HTTP_ACCEPT_LANGUAGE']:null);
        if(empty($inputLocales)) {
            return null;
        }
    
        // Generate mapping of priority => list of languages at this priority
        // break up string into pieces (languages and q factors)
        preg_match_all('/(?<code>[a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(?<priority>1|0\.[0-9]+))?/i', $inputLocales, $parsedLocales);
    
        $prioritisedLocales=array();
        if(count($parsedLocales['code'])) {
            // create a list like "en" => 0.8
            $parsedLocales=array_combine($parsedLocales['code'], $parsedLocales['priority']);
    
            // Generate nested list of priorities => [languages]
            foreach ($parsedLocales as $language => $priority) {
                $priority=(empty($priority) ? 1.0:floatval($priority));
                if(empty($prioritisedLocales[$priority])) {
                    $prioritisedLocales[$priority] = array();
                }
                
                $prioritisedLocales[$priority][]=$language;
            }
                
            // sort list based on value
            krsort($prioritisedLocales, SORT_NUMERIC);
        }
    
        // Check each requested language against loaded languages
        foreach ($prioritisedLocales as $priority=>$parsedLocales) {
            foreach($parsedLocales as $browserLocale) {
                foreach(Translatable::get_allowed_locales() as $language) {
                    if(stripos(preg_replace('/_/', '-', $language), $browserLocale)===0) {
                        return $language;
                    }
                }
            }
        }
    
        return null;
    }
    
    
    
    /**
     * Returns TRUE if a request to a certain page should be redirected to the site root (i.e. if the page acts as the home page).
     * @param {SiteTree} $page
     * @return {bool}
     */
    public static function should_be_on_root(SiteTree $page) {
        if(!self::$is_at_root && self::get_homepage_link()==trim($page->RelativeLink(true), '/')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Sets the whether to use the locale in the url or just the language
     * @param {bool} $value True to use the full locale (i.e. en_US) in the url or just the language
     * 
     * @deprecated 3.2 Use the "MultilingualRootURLController.UseLocaleURL" config setting instead
     */
    public static function set_use_locale_url($value) {
        Deprecation::notice('3.2', 'Use the "MultilingualRootURLController.UseLocaleURL" config setting instead');
        Config::inst()->update('MultilingualRootURLController', 'UseLocaleURL', $value);
    }
    
    /**
     * Sets the whether to use the locale in the url or just the language
     * @return {bool} True to use the full locale (i.e. en_US) in the url or just the language
     * 
     * @deprecated 3.2 Use the "MultilingualRootURLController.UseLocaleURL" config setting instead
     */
    public static function get_use_locale_url() {
        Deprecation::notice('3.2', 'Use the "MultilingualRootURLController.UseLocaleURL" config setting instead');
        return Config::inst()->get('MultilingualRootURLController', 'UseLocaleURL');
    }
}
?>