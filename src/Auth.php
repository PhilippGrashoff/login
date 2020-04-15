<?php

namespace atk4\login;

/**
 * Authentication controller. Add this to your application somewhere
 * and it will work wonders
 */
class Auth
{
    use \atk4\core\SessionTrait;
    use \atk4\core\ContainerTrait;
    use \atk4\core\FactoryTrait;
    use \atk4\core\AppScopeTrait;
    use \atk4\core\DIContainerTrait;
    use \atk4\core\TrackableTrait;
    use \atk4\core\HookTrait;
    use \atk4\core\InitializerTrait {
        init as _init;
    }

    /**
     * Contains information about a current user. Unlike Model this will
     * contain a record loaded from session cache.
     *
     * @var \atk4\data\Model
     */
    public $user = null;

    /**
     * Login Form. If you want to use a different LoginForm you can pass
     * a seed or object here.
     *
     * @var string|\atk4\ui\Form
     */
    public $form = \atk4\login\LoginForm::class;

    /**
     * Which field to look up user by.
     *
     * @var string
     */
    public $fieldLogin = 'email';

    /**
     * Password to be verified when authenticating.
     *
     * @var string
     */
    public $fieldPassword = 'password';

    /**
     * Permorm check automatically and display a Login form when 'setModel' takes place.
     *
     * This is a transparent way to add authentication to an existing application.
     *
     * @var bool
     */
    public $check = true;

    /**
     * Which is the index page? This page should have auth / check.
     *
     * @var string
     */
    public $pageDashboard = null;

    /**
     * User will be sent to exit page when he logs out.
     *
     * @var string
     */
    public $pageExit = 'index';

    /**
     * Should we add User Menu to Admin layout?
     *
     * @var bool
     */
    public $hasUserMenu = true;

    /**
     * Should we display and handle preferences link in user menu?
     *
     * @var bool
     */
    public $hasPreferences = true;

    /**
     * Constructor.
     *
     * @param array $options
     */
    public function __construct($options = [])
    {
        $this->setDefaults($options);
    }

    /**
     * Initialization.
     */
    public function init()
    {
        $this->_init();
        switch (session_status()) {
            case PHP_SESSION_DISABLED:
                // @codeCoverageIgnoreStart - impossible to test
                throw new \atk4\core\Exception(['Sessions are disabled on server']);
                // @codeCoverageIgnoreEnd
                break;
            case PHP_SESSION_NONE:
                session_start();
                break;
        }
    }

    /**
     * Return session persistence object.
     *
     * @return \atk4\data\Persistence\Array_
     */
    public function getSessionPersistence()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return new \atk4\data\Persistence\Array_($_SESSION[$this->name]);
    }

    /**
     * Specify a model for a user check here.
     *
     * @param \atk4\data\Model $model
     * @param string $fieldLogin
     * @param string $fieldPassword
     *
     * @throws \atk4\core\Exception
     *
     * @return $this
     */
    public function setModel($model, $fieldLogin = null, $fieldPassword = null)
    {
        $this->user = $model;

        if ($fieldLogin) {
            $this->fieldLogin = $fieldLogin;
        }

        if ($fieldPassword) {
            $this->fieldPassword = $fieldPassword;
        }

        $this->user->data = $this->getSessionPersistence()->tryLoad($this->user, 1) ?: [];
        $this->user->id = $this->user->data ? $this->user->data[$this->user->id_field] : null;

        // update session persistence after changes saved in user model
        $this->user->addHook('afterSave', function($m) {
            $this->getSessionPersistence()->update($m, 1, $m->get());
        });

        // validate user
        if ($this->check) {
            $this->check();
        }

        return $this;
    }

    /**
     * Link ACL object with this Auth controller object, apply restrictions on user model and
     * also apply ACL restrictions on each model you add to this persistence in future.
     *
     * @param \atk4\login\ACL $acl
     * @param \atk4\data\Persistence $persistence Optional persistence, use User model persistence by default
     *
     * @throws \atk4\core\Exception
     *
     * @return $this
     */
    public function setACL(\atk4\login\ACL $acl, \atk4\data\Persistence $persistence = null)
    {
        $persistence = $persistence ?? $this->user->persistence;
        $acl->auth = $this;
        $acl->applyRestrictions($this->user->persistence, $this->user);
        $persistence->addHook('afterAdd', [$acl, 'applyRestrictions']);

        return $this;
    }

    /**
     * Logout user.
     */
    public function logout()
    {
        $this->getSessionPersistence()->delete($this->user, 1);
    }

    /**
     * Call this method to verify credentials.
     *
     * It will show login form in case user is not already logged in.
     */
    public function check()
    {        
        if ($this->user->loaded()) {
            // if user is already logged in
            $this->addUserMenu();
        }
        else {
            // if user is not logged in, then show login form
            $this->displayLoginForm();
        }
    }
    
    public function addUserMenu()
    {
        // add admin menu
        if ($this->hasUserMenu && $this->app->layout instanceof \atk4\ui\Layout\Admin) {
            $m = $this->app->layout->menuRight->addMenu($this->user->getTitle());
            
            if ($this->hasPreferences) {
                $m->addItem(['Preferences', 'icon'=>'user'], [$this->pageDashboard, 'preferences'=>true]);
            }
            
            $m->addItem(['Logout', 'icon'=>'sign out'], [$this->pageDashboard, 'logout'=>true]);
        }
        
        // add preferences menu item
        if ($this->hasPreferences && $this->app->stickyGet('preferences')) {
            $this->app->add(['Header', 'User Preferences', 'subHeader'=>$this->user->getTitle(), 'icon'=>'user']);
            $this->app->add('Form')->setModel($this->user);
            exit;
        }
        
        if (isset($_GET['logout'])) {
            $this->logout();
            $this->app->redirect([$this->pageExit]);
        }
    }
    
    public function displayLoginForm()
    {
        $login = new \atk4\ui\App($this->app->title.' - Log-in Required');
        $this->app->catch_runaway_callbacks = false;
        $this->app->run_called = true;
        $login->catch_runaway_callbacks = false;
        $login->initLayout(new \atk4\login\Layout\Narrow());
        
        $login->add([
                $this->form,
                'auth' => $this,
                'linkSuccess' => [$this->pageDashboard],
                'linkForgot' => false,
        ]);
        
        $login->layout->template->set('title', 'Log-in Required');
        
        $login->run();
        $this->app->terminate();
        exit;
    }

    /**
     * Try to log in user.
     *
     * @param string $email
     * @param string $password
     *
     * @throws \atk4\core\Exception
     *
     * @return bool
     */
    public function tryLogin($email, $password)
    {
        $user = clone $this->user;
        $user->unload();

        $user->tryLoadBy($this->fieldLogin, $email);
        if ($user->loaded()) {

            // verify if the password matches
            if ($user->compare($this->fieldPassword, $password)) {
                $this->hook('loggedIn', [$user]);
                $this->getSessionPersistence()->update($user, 1, $user->get());
                return true;
            }
            $this->hook('badLogin', [$email]);
        }
        return false;
    }
}
