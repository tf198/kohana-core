<?php defined('SYSPATH') or die('No direct script access.');
/**
 * HTTT Caching adaptor class that provides caching services to the
 * [Request_Client] class, using HTTP cache control logic as defined in
 * RFC 2616.
 *
 * @package    Kohana
 * @category   Base
 * @author     Kohana Team
 * @copyright  (c) 2008-2011 Kohana Team
 * @license    http://kohanaframework.org/license
 * @since      3.2.0
 */
class Kohana_HTTP_Cache {

	const CACHE_STATUS_KEY    = 'x-cache-status';
	const CACHE_STATUS_SAVED  = 'SAVED';
	const CACHE_STATUS_HIT    = 'HIT';
	const CACHE_STATUS_MISS   = 'MISS';
	const CACHE_HIT_KEY       = 'x-cache-hits';

	/**
	 * Basic cache key generator that hashes the entire request and returns
	 * it. This is fine for static content, or dynamic content where user
	 * specific information is encoded into the request.
	 * 
	 *      // Generate cache key
	 *      $cache_key = HTTP_Cache::basic_cache_key_generator($request);
	 *
	 * @param   Request   request 
	 * @return  string
	 */
	public static function basic_cache_key_generator(Request $request)
	{
		$uri     = $request->uri();
		$query   = $request->query();
		$headers = $request->headers()->getArrayCopy();
		$body    = $request->body();

		return sha1($uri.'?'.implode('&', $query).'~'.implode('~', $headers).'~'.$body);
	}

	/**
	 * @var     Cache    cache driver to use for HTTP caching
	 */
	protected $_cache;

	/**
	 * @var    callback  Cache key generator callback
	 */
	protected $_cache_key_callback;

	/**
	 * @var    boolean   Defines whether this client should cache `private` cache directives
	 * @see    http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.9
	 */
	protected $_allow_private_cache = FALSE;

	/**
	 * @var    int       The timestamp of the request
	 */
	protected $_request_time;

	/**
	 * @var    int       The timestamp of the response
	 */
	protected $_response_time;

	/**
	 * Constructor method for this class. Allows dependency injection of the
	 * required components such as `Cache` and the cache key generator.
	 *
	 * @param   array $options 
	 */
	public function __construct(array $options = array())
	{
		foreach ($options as $key => $value)
		{
			if (method_exists($this, $key))
			{
				$this->$key($value);
			}
		}

		if ($this->_cache_key_callback === NULL)
		{
			$this->cache_key_callback('HTTP_Cache::basic_cache_key_generator');
		}
	}

	/**
	 * undocumented function
	 *
	 * @param   Request_Client  client to execute with Cache-Control
	 * @param   Request   request to execute with client
	 * @return  [Response]
	 */
	public function execute(Request_Client $client, Request $request)
	{
		// If this is a destructive request, by-pass cache completely
		if (in_array($request->method(), array(
			HTTP_Request::POST, 
			HTTP_Request::PUT, 
			HTTP_Request::DELETE)))
		{
			$response = $client->execute_client($request);

			$cache_control = HTTP_Header::create_cache_control(array(
				'no-cache',
				'must-revalidate'
			));

			// Ensure client respects destructive action
			return $response->headers('cache-control', $cache_control);
		}

		
	}

	/**
	 * Invalidate a cached response for the [Request] supplied.
	 * This has the effect of deleting the response from the
	 * [Cache] entry.
	 *
	 * @param   Request  $request Response to remove from cache
	 * @return  void
	 */
	public function invalidate_cache(Request $request)
	{
		if (($cache = $this->cache()) instanceof Cache)
		{
			$cache->delete($this->_create_cache_key($request));
		}

		return;
	}

	/**
	 * Getter and setter for the internal caching engine,
	 * used to cache responses if available and valid.
	 *
	 * @param   Kohana_Cache  cache engine to use for caching
	 * @return  Kohana_Cache
	 * @return  Kohana_Request_Client
	 */
	public function cache(Cache $cache = NULL)
	{
		if ($cache === NULL)
			return $this->_cache;

		$this->_cache = $cache;
		return $this;
	}

	/**
	 * Gets or sets the [Request_Client::allow_private_cache] setting.
	 * If set to `TRUE`, the client will also cache cache-control directives
	 * that have the `private` setting.
	 *
	 * @see     http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.9
	 * @param   boolean  allow caching of privately marked responses
	 * @return  boolean
	 * @return  [Request_Client]
	 */
	public function allow_private_cache($setting = NULL)
	{
		if ($setting === NULL)
			return $this->_allow_private_cache;

		$this->_allow_private_cache = (bool) $setting;
		return $this;
	}

	/**
	 * Sets or gets the cache key generator callback for this caching
	 * class. The cache key generator provides a unique hash based on the
	 * `Request` object passed to it.
	 * 
	 * The default generator is [HTTP_Cache::basic_cache_key_generator()], which
	 * serializes the entire `HTTP_Request` into a unique sha1 hash. This will
	 * provide basic caching for static and simple dynamic pages. More complex
	 * algorithms can be defined and then passed into `HTTP_Cache` using this
	 * method.
	 * 
	 *      // Get the cache key callback
	 *      $callback = $http_cache->cache_key_callback();
	 * 
	 *      // Set the cache key callback
	 *      $http_cache->cache_key_callback('Foo::cache_key');
	 * 
	 *      // Alternatively, in PHP 5.3 use a closure
	 *      $http_cache->cache_key_callback(function (Request $request) {
	 *            return sha1($request->render());
	 *      });
	 *
	 * @param   callback  callback 
	 * @return  mixed
	 * @throws  HTTP_Exception
	 */
	public function cache_key_callback($callback = NULL)
	{
		if ($callback === NULL)
			return $this->_cache_key_callback;

		if ( ! is_callable($callback))
			throw new HTTP_Exception('cache_key_callback must be callable!');

		$this->_cache_key_callback = $callback;
		return $this;
	}

	/**
	 * Creates a cache key for the request to use for caching
	 * [Kohana_Response] returned by [Request::execute].
	 * 
	 * This is the default cache key generating logic, but can be overridden
	 * by setting [HTTP_Cache::$_cache_key_callback].
	 *
	 * @param   Request  request
	 * @return  string
	 * @uses    [HTTP_Cache::cache_key_callback()]
	 */
	public function create_cache_key(Request $request)
	{
		return call_user_func($this->cache_key_callback(), $request);
	}

	/**
	 * Controls whether the response can be cached. Uses HTTP
	 * protocol to determine whether the response can be cached.
	 *
	 * @link    RFC 2616 http://www.w3.org/Protocols/rfc2616/
	 * @param   Response  $response The Response
	 * @return  boolean
	 */
	public function set_cache(Response $response)
	{
		$headers = (array) $response->headers();
		if ($cache_control = arr::get($headers, 'cache-control'))
		{
			// Parse the cache control
			$cache_control = HTTP_Header::parse_cache_control( (string) $cache_control);

			// If the no-cache or no-store directive is set, return
			if (array_intersect_key($cache_control, array('no-cache' => NULL, 'no-store' => NULL)))
				return FALSE;

			// Get the directives
			$directives = array_keys($cache_control);

			// Check for private cache and get out of here if invalid
			if ( ! $this->_allow_private_cache AND in_array('private', $directives))
			{
				if ( ! isset($cache_control['s-maxage']))
					return FALSE;

				// If there is a s-maxage directive we can use that
				$cache_control['max-age'] = $cache_control['s-maxage'];
			}

			// Check that max-age has been set and if it is valid for caching
			if (isset($cache_control['max-age']) AND (int) $cache_control['max-age'] < 1)
				return FALSE;
		}

		if ($expires = arr::get($headers, 'expires') AND ! isset($cache_control['max-age']))
		{
			// Can't cache things that have expired already
			if (strtotime( (string) $expires) <= time())
				return FALSE;
		}

		return TRUE;
	}

	/**
	 * Caches a [Response] using the supplied [Cache]
	 * and the key generated by [Request_Client::_create_cache_key].
	 *
	 * If not response is supplied, the cache will be checked for an existing
	 * one that is available.
	 *
	 * @param   Request   request  The request
	 * @param   Response  response Response
	 * @param   callback  cachekey generating callback
	 * @return  mixed
	 */
	public function cache_response(Request $request, Response $response = NULL)
	{
		if ( ! ($cache = $this->cache()) instanceof Cache)
			return FALSE;

		// Check for Pragma: no-cache
		if ($pragma = $request->headers('pragma'))
		{
			if ($pragma instanceof HTTP_Header_Value AND $pragma->key() == 'no-cache')
				return FALSE;
			elseif (is_array($pragma) AND isset($pragma['no-cache']))
				return FALSE;
		}

		// If there is no response, lookup an existing cached response
		if ( ! $response)
		{
			$response = $cache->get($this->create_cache_key($request));

			if ( ! $response instanceof Response)
			{
				$request->headers(Request_Client::CACHE_STATUS_KEY, Request_Client::CACHE_STATUS_MISS);
				return FALSE;
			}

			// Update the header to have correct HIT status and count
			$cache_status = $response->headers(Request_Client::CACHE_STATUS_KEY);
			$cache_status->value(Request_Client::CACHE_STATUS_HIT);

			return $response;
		}
		else
		{
			if (($ttl = $this->cache_lifetime($response)) === FALSE)
				return FALSE;

			$response->headers(Request_Client::CACHE_STATUS_KEY,
				Request_Client::CACHE_STATUS_SAVED);
			return $cache->set($this->create_cache_key($request), $response, $ttl);
		}
	}

	/**
	 * Calculates the total Time To Live based on the specification
	 * RFC 2616 cache lifetime rules.
	 *
	 * @param   Response  $response  Response to evaluate
	 * @return  mixed  TTL value or false if the response should not be cached
	 */
	public function cache_lifetime(Response $response)
	{
		// Get out of here if this cannot be cached
		if ( ! $this->set_cache($response))
			return FALSE;

		// Calculate apparent age
		if ($date = $response->headers('date'))
		{
			$apparent_age = max(0, $this->_response_time - strtotime( (string) $date));
		}
		else
		{
			$apparent_age = max(0, $this->_response_time);
		}

		// Calculate corrected received age
		if ($age = $response->headers('age'))
		{
			$corrected_received_age = max($apparent_age, intval( (string) $age));
		}
		else
		{
			$corrected_received_age = $apparent_age;
		}

		// Corrected initial age
		$corrected_initial_age = $corrected_received_age + $this->request_execution_time();

		// Resident time
		$resident_time = time() - $this->_response_time;

		// Current age
		$current_age = $corrected_initial_age + $resident_time;

		// Prepare the cache freshness lifetime
		$ttl = NULL;

		// Cache control overrides
		if ($cache_control = $response->headers('cache-control'))
		{
			// Parse the cache control header
			$cache_control = HTTP_Header::parse_cache_control( (string) $cache_control);

			if (isset($cache_control['max-age']))
			{
				$ttl = (int) $cache_control['max-age'];
			}

			if (isset($cache_control['s-maxage']) AND isset($cache_control['private']) AND $this->_allow_private_cache)
			{
				$ttl = (int) $cache_control['s-maxage'];
			}

			if (isset($cache_control['max-stale']) AND ! isset($cache_control['must-revalidate']))
			{
				$ttl = $current_age + (int) $cache_control['max-stale'];
			}
		}

		// If we have a TTL at this point, return
		if ($ttl !== NULL)
			return $ttl;

		if ($expires = $response->headers('expires'))
			return strtotime( (string) $expires) - $current_age;

		return FALSE;
	}

	/**
	 * Returns the duration of the last request execution.
	 * Either returns the time of completed requests or
	 * `FALSE` if the request hasn't finished executing, or
	 * is yet to be run.
	 *
	 * @return  mixed
	 */
	public function request_execution_time()
	{
		if ($this->_request_time === NULL OR $this->_response_time === NULL)
			return FALSE;

		return $this->_response_time - $this->_request_time;
	}

} // End Kohana_HTTP_Cache