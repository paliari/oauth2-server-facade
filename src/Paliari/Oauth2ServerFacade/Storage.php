<?php
namespace Paliari\Oauth2ServerFacade;

use OAuth2\Storage\Pdo;

class Storage extends Pdo
{

    /**
     * @param \Doctrine\DBAL\Connection $connection
     * @param array                     $config
     */
    public function __construct($connection, $config = array())
    {
        $this->db     = $connection;
        $this->config = array_merge(array(
            'client_table'        => 'oauth_clients',
            'access_token_table'  => 'oauth_access_tokens',
            'refresh_token_table' => 'oauth_refresh_tokens',
            'client_user_table'   => 'oauth_client_user',
            'code_table'          => 'oauth_authorization_codes',
            'user_table'          => 'oauth_users',
            'jwt_table'           => 'oauth_jwt',
        ), $config);
    }

    public function getUser($user_id)
    {
        $sql = 'SELECT * from usuarios';
        $sql .= ' where id=:user_id';
        $params = array('user_id' => $user_id);
        if (!$userInfo = $this->fetch($sql, $params)) {
            return false;
        }

        return $userInfo;
    }

    /* OAuth2_Storage_AuthorizationCodeInterface */
    public function getAuthorizationCode($code)
    {
        $sql  = sprintf('SELECT * from %s where authorization_code = :code', $this->config['code_table']);
        $code = $this->fetch($sql, compact('code'));
        if ($code) {
            // convert date string back to timestamp
            $code['expires'] = strtotime($code['expires']);
        }

        return $code;
    }

    /* OAuth2_Storage_ClientCredentialsInterface */
    public function checkClientCredentials($client_id, $client_secret = null)
    {
        $sql    = sprintf('SELECT * from %s where client_id = :client_id', $this->config['client_table']);
        $result = $this->fetch($sql, compact('client_id'));

        // make this extensible
        return $result ? $result['client_secret'] == $client_secret : false;
    }

    public function getClientDetails($client_id)
    {
        $sql = sprintf('SELECT * from %s where client_id = :client_id', $this->config['client_table']);

        return $this->fetch($sql, compact('client_id'));
    }

    /* OAuth2_Storage_AccessTokenInterface */
    public function getAccessToken($access_token)
    {
        $sql   = sprintf('SELECT * from %s where access_token = :access_token', $this->config['access_token_table']);
        $token = $this->fetch($sql, compact('access_token'));
        if ($token) {
            // convert date string back to timestamp
            $token['expires'] = strtotime($token['expires']);
        }

        return $token;
    }

    /* OAuth2_Storage_RefreshTokenInterface */
    public function getRefreshToken($refresh_token)
    {
        $sql   = sprintf('SELECT * from %s where refresh_token = :refresh_token', $this->config['refresh_token_table']);
        $token = $this->fetch($sql, compact('refresh_token'));
        if ($token) {
            // convert expires to epoch time
            $token['expires'] = strtotime($token['expires']);
        }

        return $token;
    }

    /**
     * @param string $sql
     * @param array  $params
     *
     * @return array|bool
     */
    public function fetch($sql, $params = array())
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if (!$res = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            return false;
        }

        return $res;
    }

    /**
     * Garbage Collection
     */
    public function gc()
    {
        $expires = date('Y-m-d H:i:s');
        $tables  = array(
            'access_token_table',
            'refresh_token_table',
            'code_table',
        );
        foreach ($tables as $table) {
            $stmt = $this->db->prepare(sprintf('DELETE FROM %s WHERE expires < :expires', $this->config[$table]));
            $stmt->execute(compact('expires'));
        }
    }

    /**
     * @param string $client_id
     * @param string $user_id
     *
     * @return bool
     */
    public function setClientUser($client_id, $user_id)
    {
        if ($this->getClientUser($client_id, $user_id)) {
            return true;
        } else {
            $created_at = date('Y-m-d H:i:s');
            $sql        = 'INSERT INTO %s (client_id, user_id, created_at) VALUES (:client_id, :user_id, :created_at)';
            $sql        = sprintf($sql, $this->config['client_user_table']);

            $params = array_map('trim', compact('client_id', 'user_id', 'created_at'));

            return $this->db->prepare($sql)->execute($params);
        }
    }

    /**
     * @param string $client_id
     * @param string $user_id
     *
     * @return array|bool
     */
    public function getClientUser($client_id, $user_id)
    {
        $sql = 'SELECT * from %s where client_id = :client_id and user_id = :user_id';
        $sql = sprintf($sql, $this->config['client_user_table']);

        return $this->fetch($sql, compact('client_id', 'user_id'));
    }

    /**
     * @param string $client_id
     * @param string $user_id
     *
     * @return bool
     */
    public function removeClientUser($client_id, $user_id)
    {
        $sql    = 'DELETE from %s where client_id = :client_id and user_id = :user_id';
        $sql    = sprintf($sql, $this->config['client_user_table']);
        $params = array_map('trim', compact('client_id', 'user_id'));

        return $this->db->prepare($sql)->execute($params);
    }

}
