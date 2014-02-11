<?php namespace TypiCMS\Modules\Users\Repositories;

// Part of the code is from https://github.com/brunogaspar/laravel4-starter-kit

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use Exception;

use Cartalyst\Sentry\Sentry;

use Cartalyst\Sentry\Users\UserNotFoundException;
use Cartalyst\Sentry\Users\UserAlreadyActivatedException;
use Cartalyst\Sentry\Users\LoginRequiredException;
use Cartalyst\Sentry\Users\PasswordRequiredException;
use Cartalyst\Sentry\Users\WrongPasswordException;
use Cartalyst\Sentry\Users\UserNotActivatedException;
use Cartalyst\Sentry\Users\UserExistsException;

use Cartalyst\Sentry\Groups\GroupNotFoundException;

use Cartalyst\Sentry\Throttling\UserSuspendedException;
use Cartalyst\Sentry\Throttling\UserBannedException;

class SentryUser implements UserInterface {

	/**
	 * Construct a new SentryUser Object
	 */
	public function __construct(Sentry $sentry)
	{
		$this->sentry = $sentry;

		// Get the Throttle Provider
		$this->throttleProvider = $this->sentry->getThrottleProvider();

		// Enable the Throttling Feature
		$this->throttleProvider->enable();
	}

	/**
	 * Get all models
	 *
	 * @param boolean $all Show published or all
	 * @return StdClass Object with $items
	 */
	public function getAll($all = false)
	{
		$users = $this->sentry->findAllUsers();

		foreach ($users as $user) {
			if ($user->isActivated()) {
				$user->status = 'Active';
			} else {
				$user->status = 'Not Active';
			}

			// Pull Suspension & Ban info for this user
			$throttle = $this->throttleProvider->findByUserId($user->id);

			// Check for suspension
			if ($throttle->isSuspended()) {
				// User is Suspended
				$user->status = 'Suspended';
			}

			// Check for ban
			if ($throttle->isBanned()) {
				// User is Banned
				$user->status = 'Banned';
			}
		}

		return $users;
	}


	/**
	 * Retrieve user by id
	 * regardless of status
	 *
	 * @param  int $id user ID
	 * @return User object
	 */
	public function byId($id)
	{
		try {
			return $this->sentry->findUserById($id);
		} catch (UserNotFoundException $e) {
			$error = 'User not found.';
		}
		throw new Exception($error);
	}


	/**
	 * Retrieve user by login
	 * regardless of status
	 *
	 * @param  int $login
	 * @return User object
	 */
	public function findUserByLogin($login)
	{
		try {
			return $this->sentry->findUserByLogin($login);
		} catch (UserNotFoundException $e) {
			$error = 'User not found.';
		}
		throw new Exception($error);
	}


	/**
	 * Retrieve all groups or user groups
	 *
	 * @param  User $user
	 * @return array
	 */
	public function getGroups($user = null)
	{
		$groups = $this->sentry->findAllGroups();

		if ($user) {

			$userGroups = $user->getGroups()->lists('name', 'id');
			$selectedGroups = array();
			foreach ($groups as $keyGroup => $group) {
				$selectedGroups['groups['.$group->id.']'] = in_array($group->name, $userGroups) ? true : false ;
			}
			return $selectedGroups;

		} else {

			$groupsSelect = array();
			foreach ($groups as $group) {
				$groupsSelect[$group->name] = 'groups['.$group->id.']';
			}
			return $groupsSelect;

		}

	}


	/**
	 * Get reset password code for user
	 *
	 * @param  User $user
	 * @return string
	 */
	public function getResetPasswordCode($user)
	{
		return $user->getResetPasswordCode();
	}


	/**
	 * Check reset password code for user
	 *
	 * @param  User $user
	 * @param  String $resetCode
	 * @return bool
	 */
	public function checkResetPasswordCode($user, $resetCode)
	{
		return $user->checkResetPasswordCode($resetCode);
	}


	/**
	 * Attempt reset password for user
	 *
	 * @param  User $user
	 * @param  String $resetCode
	 * @param  String $password
	 * @return bool
	 */
	public function attemptResetPassword($user, $resetCode, $password)
	{
		return $user->attemptResetPassword($resetCode, $password);
	}


	/**
	 * Get id of user
	 *
	 * @param  User $user
	 * @return string
	 */
	public function getId($user)
	{
		return $user->getId();
	}


	/**
	 * Create a new model
	 *
	 * @param array  Data to create a new object
	 * @return boolean
	 */
	public function create(array $data)
	{
		$errors = array();
		try {
			// Create the user
			$userData = array_except($data, array('_method','_token', 'exit', 'groups', 'password_confirmation'));
			$user = $this->sentry->createUser($userData);

			$allGroups = $this->sentry->findAllGroups();
			
			foreach ($allGroups as $group) {
				if ($data['groups'][$group->id]) {
					$user->addGroup($group);
				} else {
					$user->removeGroup($group);
				}
			}

		} catch (LoginRequiredException $e) {
			exit($e->getMessage());
			$errors['email'][] = $e->getMessage();
		} catch (PasswordRequiredException $e) {
			exit($e->getMessage());
			$errors['password'][] = $e->getMessage();
		} catch (UserExistsException $e) {
			exit($e->getMessage());
			$errors['email'][] = $e->getMessage();
		} catch (GroupNotFoundException $e) {
			exit($e->getMessage());
			$errors['group'][] = $e->getMessage();
		}

		return $errors ? false : true ;

	}


	/**
	 * Update an existing user
	 *
	 * @param array  Data to update a user
	 * @return boolean
	 */
	public function update(array $data)
	{

		$user = $this->sentry->findUserById($data['id']);

		$allGroups = $this->sentry->findAllGroups();
		
		foreach ($allGroups as $group) {
			if ($data['groups'][$group->id]) {
				$user->addGroup($group);
			} else {
				$user->removeGroup($group);
			}
		}

		$data = array_except($data, array('_method', '_token', 'exit', 'groups'));

		if ( ! $data['password']) {
			$data = array_except($data, 'password');
		}

		foreach ($data as $key => $value) {
			$user->$key = $value;
		}

		$user->save();
		
		return true;
		
	}

	/**
	 * Authenticate a user
	 *
	 * @param array $credentials
	 * @param boolean $id
	 * @return boolean
	 */
	public function authenticate($credentials, $remember = false)
	{
		try {
			return $this->sentry->authenticate($credentials, $remember);
		} catch (LoginRequiredException $e) {
			$error = 'Login field is required.';
		} catch (PasswordRequiredException $e) {
			$error = 'Password field is required.';
		} catch (WrongPasswordException $e) {
			$error = 'Wrong password, try again.';
		} catch (UserNotFoundException $e) {
			$error = 'User not found.';
		} catch (UserNotActivatedException $e) {
			$error = 'User not activated.';
		} catch (UserSuspendedException $e) {
			$error = 'User is suspended for [$time] minutes.';
		} catch (UserBannedException $e) {
			$error = 'User is banned.';
		}
		throw new Exception($error);
	}


	/**
	 * Register a new user
	 *
	 * @param array $input
	 * @return boolean
	 */
	public function register(array $input, $noConfirmation = null)
	{

		try {
			// Let's register a user.
			$user = $this->sentry->register($input, $noConfirmation);

			if ($noConfirmation) {

				// Add this person to the user group. 
				$userGroup = $this->sentry->getGroupProvider()->findById(1);
				$user->addGroup($userGroup);

			} else {

				// Get the activation code & prep data for email
				$data['activationCode'] = $user->GetActivationCode();
				$data['email'] = $input['email'];
				$data['firstName'] = $input['first_name'];
				$data['lastName'] = $input['last_name'];
				$data['userId'] = $user->getId();

				// send email with link to activate.
				\Mail::send('emails.auth.welcome', $data, function($m) use($data)
				{
					$m->to($data['email'])->subject('Welcome to Typi CMS');
				});
			}

			return true;

		} catch (Cartalyst\Sentry\Users\LoginRequiredException $e) {
			$error = 'Login field is required.';
		} catch (Cartalyst\Sentry\Users\PasswordRequiredException $e) {
			$error = 'Password field is required.';
		} catch (Cartalyst\Sentry\Users\UserExistsException $e) {
			$error = 'User with this login already exists.';
		}
		throw new Exception($error);

	}


	/**
	 * Activate a user registration
	 *
	 * @param int $userId
	 * @param string $activationCode
	 * @return boolean
	 */
	public function activate($userId = null, $activationCode = null)
	{
		try {
			$user = $this->sentry->getUserProvider()->findById($userId);

			if ($user->attemptActivation($activationCode)) {
				
				$userGroup = $this->sentry->getGroupProvider()->findById(1);
				$user->addGroup($userGroup);

				return true;

			} else {
				$error = 'There was a problem activating this account.';
			}
		} catch (UserNotFoundException $e) {
			$error = 'User does not exist.';
		} catch (UserAlreadyActivatedException $e) {
			$error = 'You have already activated this account.';
		}
		throw new Exception($error);

	}


	/**
	 * Logout a user
	 *
	 * @return null
	 */
	public function logout()
	{
		return $this->sentry->logout();
	}


	/**
	 * Update a user
	 *
	 * @param int $id
	 * @return boolean
	 */
	public function destroy($id)
	{

		$user = $this->sentry->findUserById($id);
		return $user->delete() ? true : false ;

	}


}