<?php declare(strict_types=1);
/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012, 2022 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

namespace Elabftw\Models;

use Elabftw\Elabftw\Db;
use Elabftw\Enums\Action;
use Elabftw\Enums\State;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Interfaces\RestInterface;
use Elabftw\Traits\SetIdTrait;
use PDO;

/**
 * All about Links
 */
abstract class AbstractLinks implements RestInterface
{
    use SetIdTrait;

    protected Db $Db;

    public function __construct(public AbstractEntity $Entity, ?int $id = null)
    {
        $this->Db = Db::getConnection();
        // this field corresponds to the target id (link_id)
        $this->id = $id;
    }

    public function getPage(): string
    {
        return sprintf('%s%d/%s/', $this->Entity->getPage(), $this->Entity->id ?? '', $this->getTable());
    }

    public function patch(Action $action, array $params): array
    {
        return array();
    }

    /**
     * Get links for an entity
     */
    public function readAll(): array
    {
        // main category table
        $sql = 'SELECT entity.id AS itemid,
            entity.title,
            entity.elabid,
            categoryt.title AS category_title,
            categoryt.color AS category_color,
            ' . ($this instanceof ItemsLinks ? 'entity.is_bookable,' : '') . '
            entity.state AS link_state
            FROM ' . $this->getTable() . '
            LEFT JOIN ' . $this->getTargetType() . ' AS entity ON (' . $this->getTable() . '.link_id = entity.id)
            LEFT JOIN ' . $this->getCatTable() . ' AS categoryt ON (entity.category = categoryt.id)
            WHERE ' . $this->getTable() . '.item_id = :id AND (entity.state = :state OR entity.state = :statearchived)
            ORDER by categoryt.title ASC, entity.date ASC, entity.title ASC';

        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->Entity->id, PDO::PARAM_INT);
        $req->bindValue(':state', State::Normal->value, PDO::PARAM_INT);
        $req->bindValue(':statearchived', State::Archived->value, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readOne(): array
    {
        return $this->readAll();
    }

    /**
     * Get related entities
     */
    public function readRelated(): array
    {
        $sql = 'SELECT entity.id AS entityid, entity.title, categoryt.title AS category_title, categoryt.color AS category_color, entity.state AS link_state';

        if ($this instanceof ItemsLinks) {
            $sql .= ', entity.is_bookable';
        }

        $sql .= ' FROM ' . $this->getRelatedTable() . ' as entity_links
            LEFT JOIN ' . $this->getTargetType() . ' AS entity ON (entity_links.item_id = entity.id)
            LEFT JOIN ' . $this->getCatTable() . ' AS categoryt ON (entity.category = categoryt.id)';

        $sql .= sprintf('WHERE entity_links.link_id = :id AND (entity.state = %d OR entity.state = %d) ORDER by', State::Normal->value, State::Archived->value);

        $sql .= ' categoryt.title ASC, entity.title ASC';

        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->Entity->id, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    /**
     * Copy the links from one entity to an other
     *
     * @param int $id The id of the original entity
     * @param int $newId The id of the new entity that will receive the links
     * @param bool $fromTpl do we duplicate from template?
     */
    public function duplicate(int $id, int $newId, $fromTpl = false): int
    {
        $table = $this->getTable();
        if ($fromTpl) {
            $table = $this->getTemplateTable();
        }
        $sql = 'INSERT INTO ' . $this->getTable() . ' (item_id, link_id)
            SELECT :new_id, link_id
            FROM ' . $table . '
            WHERE item_id = :old_id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':new_id', $newId, PDO::PARAM_INT);
        $req->bindParam(':old_id', $id, PDO::PARAM_INT);

        return (int) $this->Db->execute($req);
    }

    public function postAction(Action $action, array $reqBody): int
    {
        return match ($action) {
            Action::Create => $this->create(),
            Action::Duplicate => $this->import(),
            default => throw new ImproperActionException('Invalid action for links create.'),
        };
    }

    public function destroy(): bool
    {
        $this->Entity->canOrExplode('write');
        $this->Entity->touch();

        $sql = 'DELETE FROM ' . $this->getTable() . ' WHERE link_id = :link_id AND item_id = :item_id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':link_id', $this->id, PDO::PARAM_INT);
        $req->bindParam(':item_id', $this->Entity->id, PDO::PARAM_INT);
        return $this->Db->execute($req);
    }

    abstract protected function getTargetType(): string;

    abstract protected function getCatTable(): string;

    abstract protected function getTable(): string;

    abstract protected function getRelatedTable(): string;

    abstract protected function getTemplateTable(): string;

    abstract protected function getImportTargetTable(): string;

    /**
     * Add a link to an entity
     * Links to Items are possible from all entities
     * Links to Experiments are only allowed from other Experiments and Items
     */
    protected function create(): int
    {
        // don't insert a link to the same entity, make sure we check for the type too
        if ($this->Entity->id === $this->id && $this->Entity->type === $this->getTargetType()) {
            return 0;
        }
        $this->Entity->touch();

        // use IGNORE to avoid failure due to a key constraint violations
        $sql = 'INSERT IGNORE INTO ' . $this->getTable() . ' (item_id, link_id) VALUES(:item_id, :link_id)';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':item_id', $this->Entity->id, PDO::PARAM_INT);
        $req->bindParam(':link_id', $this->id, PDO::PARAM_INT);

        $this->Db->execute($req);

        return $this->id;
    }

    /**
     * Copy the links of an item into our entity
     * Also copy links of an experiment into our entity unless it is a template
     */
    private function import(): int
    {
        $this->Entity->canOrExplode('write');

        // the :item_id of the SELECT will be the same for all rows: our current entity id
        // use IGNORE to avoid failure due to a key constraint violations
        $sql = 'INSERT IGNORE INTO ' . $this->getTable() . ' (item_id, link_id)
            SELECT :item_id, link_id
            FROM ' . $this->getImportTargetTable() . '
            WHERE item_id = :link_id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':item_id', $this->Entity->id, PDO::PARAM_INT);
        $req->bindParam(':link_id', $this->id, PDO::PARAM_INT);

        return (int) $this->Db->execute($req);
    }
}
