<?php

/**
 * Ushahidi User Repository
 *
 * Also implements registration checks
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

namespace Ushahidi\App\Repository;

use Ohanzee\DB;
use Ohanzee\Database;
use Ushahidi\Core\Entity;
use Ushahidi\Core\Entity\User;
use Ushahidi\Core\Entity\UserRepository as UserRepositoryContract;
use Ushahidi\Core\Entity\Contact;
use Ushahidi\Core\Entity\ContactRepository;
use Ushahidi\Core\SearchData;
use Ushahidi\Core\Tool\Hasher;
use Ushahidi\Core\Usecase\User\RegisterRepository;
use Ushahidi\Core\Usecase\User\ResetPasswordRepository;

use League\Event\ListenerInterface;
use Ushahidi\Core\Traits\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class UserRepository extends OhanzeeRepository implements
    UserRepositoryContract,
    RegisterRepository,
    ResetPasswordRepository
{
    /**
     * @var Hasher
     */
    protected $hasher;

    // Use Event trait to trigger events
    use Event;

    use Concerns\UsesBulkAutoIncrement;

    /**
     * @param  Hasher $hasher
     * @return $this
     */
    public function setHasher(Hasher $hasher)
    {
        $this->hasher = $hasher;
        return $this;
    }

    // OhanzeeRepository
    protected function getTable()
    {
        return 'users';
    }

    // OhanzeeRepository
    public function getEntity(array $data = null)
    {
        if (!empty($data['id'])) {
            $data += [
                'contacts' => $this->getContacts($data['id']),
            ];
        }
        return new User($data);
    }

    /**
     * Return a SELECT query, optionally with preconditions.
     * @param  Array $where optional hash of conditions
     * @return Database_Query_Builder_Select
     */
    protected function selectQuery(array $where = [])
    {
        $query = parent::selectQuery($where);

        // Join to contacts
        $query->join('contacts', 'LEFT')->on('users.id', '=', 'contacts.user_id');

        return $query;
    }

    protected function getContacts($entity_id)
    {
        // Unfortunately there is a circular reference created if the Contact repo is
        // injected into the User repo to avoid this we access the table directly
        // NOTE: This creates a hard coded dependency on the table naming for contacts
        $query = DB::select('*')->from('contacts')
                    ->where('user_id', '=', $entity_id);

        $results = $query->execute($this->db());

        return $results->as_array();
    }

    // CreateRepository
    public function create(Entity $entity)
    {
        $state = [
            'created'  => time(),
            'password' => $this->hasher->hash($entity->password),
        ];
        $entity->setState($state);
        if ($entity->role === 'admin') {
                $this->updateIntercomAdminUsers($entity);
        }
        return parent::create($entity);
    }

    // CreateRepository
    public function createWithHash(Entity $entity)
    {
        $state = [
            'created'  => time()
        ];
        $entity->setState($state);
        if ($entity->role === 'admin') {
                $this->updateIntercomAdminUsers($entity);
        }

        return parent::create($entity);
    }

    public function createMany(Collection $collection) : array
    {
        $this->checkAutoIncMode();

        $first = $collection->first()->asArray();
        unset($first['contacts']);
        $columns = array_keys($first);

        $values = $collection->map(function ($entity) {
            $data = $entity->asArray();

            if ($data['password']) {
                $data['password'] = $this->hasher->hash($data['password']);
            }

            unset($data['contacts']);
            $data['created'] = time();

            return $data;
        })->all();

        $query = DB::insert($this->getTable())
            ->columns($columns);

        call_user_func_array([$query, 'values'], $values);

        list($insertId, $created) = $query->execute($this->db());
        $newIds = range($insertId, $insertId + $created - 1);

        $contacts = collect($newIds)
            ->combine($collection)
            ->map(function ($entity, $id) {
                return collect($entity->contacts)->map(function ($data) use ($id) {
                    return new Contact($data + ['user_id' => $id]);
                })->all();
            })
            ->flatten(1);

        if ($contacts->isNotEmpty()) {
            service('repository.contact')->createMany($contacts);
        }

        return $newIds;
    }

    // UpdateRepository
    public function update(Entity $entity)
    {
        $user = $entity->getChanged();

        unset($user['contacts']);

        $user['updated'] = time();

        if ($entity->hasChanged('password')) {
            $user['password'] = $this->hasher->hash($entity->password);
        }

        if ($entity->role === 'admin') {
            $this->updateIntercomAdminUsers($entity);
        }

        return $this->executeUpdate(['id' => $entity->id], $user);
    }

    // SearchRepository
    public function getSearchFields()
    {
        return ['email', 'role', 'q' /* LIKE realname, email */];
    }

    // SearchRepository
    public function setSearchConditions(SearchData $search)
    {
        $query = $this->search_query;
        $table = $this->getTable();

        if ($search->q) {
            $query->and_where_open();
            $query->or_where('realname', 'LIKE', "%" . $search->q . "%");
            $query->and_where_close();

            // Adding search contacts
            $query->or_where('contacts.contact', 'like', '%' . $search->q . '%');
        }

        if ($search->role) {
            $role = $search->role;
            if (!is_array($search->role)) {
                $role = explode(',', $search->role);
            }

            $query->where('role', 'IN', $role);
        }

        return $query;
    }

    // UserRepository
    public function getByEmail($email)
    {
        return $this->getEntity($this->selectOne([
            'contact' => $email,
            'type' => 'email'
        ]));
    }

    // RegisterRepository
    public function isUniqueEmail($email)
    {
        return $this->selectCount([
            'contact' => $email,
            'type' => 'email'
        ]) === 0;
    }

    // RegisterRepository
    public function register(Entity $entity)
    {

        return $this->executeInsert([
            'realname' => $entity->realname,
            'email'    => $entity->email,
            'password' => $this->hasher->hash($entity->password),
            'created'  => time()
            ]);
    }

    // ResetPasswordRepository
    public function getResetToken(Entity $entity)
    {
        $token = Hash::make(Str::random(40));

        $input = [
            'reset_token' => $token,
            'user_id' => $entity->id,
            'created' => time()
        ];

        // Save the token
        $query = DB::insert('user_reset_tokens')
            ->columns(array_keys($input))
            ->values(array_values($input))
            ->execute($this->db());

        return $token;
    }

    // ResetPasswordRepository
    public function isValidResetToken($token)
    {
        $result = DB::select([DB::expr('COUNT(*)'), 'total'])
            ->from('user_reset_tokens')
            ->where('reset_token', '=', $token)
            ->where('created', '>', time() - 1800) // Expire tokens after less than 30 mins
            ->execute($this->db());



        $count = $result->get('total') ?: 0;

        return $count !== 0;
    }

    // ResetPasswordRepository
    public function setPassword($token, $password)
    {
        $sub = DB::select('user_id')
            ->from('user_reset_tokens')
            ->where('reset_token', '=', $token);

        $this->executeUpdate(['id' => $sub], [
            'password' => $this->hasher->hash($password)
        ]);
    }

    // ResetPasswordRepository
    public function deleteResetToken($token)
    {
        $result = DB::delete('user_reset_tokens')
            ->where('reset_token', '=', $token)
            ->execute($this->db());
    }

    /**
     * Get total count of entities
     * @param  Array $where
     * @return int
     */
    public function getTotalCount(array $where = [])
    {
        return $this->selectCount($where);
    }

    // DeleteRepository
    public function delete(Entity $entity)
    {
        if ($entity->role === 'admin') {
                $this->updateIntercomAdminUsers($entity);
        }
        return parent::delete($entity);
    }

    /**
     * Pass User count to Intercom
     * takes a postive/negative offset by which to increase/decrease count for create/delete
     * @param Integer $offset
     * @return void
     */
    protected function updateIntercomAdminUsers($user)
    {
        $this->emit($this->event, $user);
    }
}
