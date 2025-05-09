<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Entity\ProjectSystem;

use Brick\Math\BigDecimal;
use Doctrine\Common\Collections\Criteria;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Serializer\Filter\PropertyFilter;
use App\ApiPlatform\Filter\LikeFilter;
use App\Entity\Attachments\Attachment;
use App\Repository\Parts\DeviceRepository;
use App\Validator\Constraints\UniqueObjectCollection;
use Doctrine\DBAL\Types\Types;
use App\Entity\Attachments\ProjectAttachment;
use App\Entity\Base\AbstractStructuralDBElement;
use App\Entity\Parameters\ProjectParameter;
use App\Entity\Parts\Part;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * This class represents a project in the database.
 *
 * @extends AbstractStructuralDBElement<ProjectAttachment, ProjectParameter>
 */
#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'projects')]
#[ApiResource(
    operations: [
        new Get(security: 'is_granted("read", object)'),
        new GetCollection(security: 'is_granted("@projects.read")'),
        new Post(securityPostDenormalize: 'is_granted("create", object)'),
        new Patch(security: 'is_granted("edit", object)'),
        new Delete(security: 'is_granted("delete", object)'),
    ],
    normalizationContext: ['groups' => ['project:read', 'api:basic:read'], 'openapi_definition_name' => 'Read'],
    denormalizationContext: ['groups' => ['project:write', 'api:basic:write', 'attachment:write', 'parameter:write'], 'openapi_definition_name' => 'Write'],
)]
#[ApiResource(
    uriTemplate: '/projects/{id}/children.{_format}',
    operations: [
        new GetCollection(
            openapi: new Operation(summary: 'Retrieves the children elements of a project.'),
            security: 'is_granted("@projects.read")'
        )
    ],
    uriVariables: [
        'id' => new Link(fromProperty: 'children', fromClass: Project::class)
    ],
    normalizationContext: ['groups' => ['project:read', 'api:basic:read'], 'openapi_definition_name' => 'Read']
)]
#[ApiFilter(PropertyFilter::class)]
#[ApiFilter(LikeFilter::class, properties: ["name", "comment"])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'id', 'addedDate', 'lastModified'])]
class Project extends AbstractStructuralDBElement
{
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[ORM\OrderBy(['name' => Criteria::ASC])]
    protected Collection $children;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id')]
    #[Groups(['project:read', 'project:write'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    protected ?AbstractStructuralDBElement $parent = null;

    #[Groups(['project:read', 'project:write'])]
    protected string $comment = '';

    /**
     * @var Collection<int, ProjectBOMEntry>
     */
    #[Assert\Valid]
    #[Groups(['extended', 'full', 'import'])]
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectBOMEntry::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[UniqueObjectCollection(message: 'project.bom_entry.part_already_in_bom', fields: ['part'])]
    #[UniqueObjectCollection(message: 'project.bom_entry.name_already_in_bom', fields: ['name'])]
    protected Collection $bom_entries;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $order_quantity = 0;

    /**
     * @var string|null The current status of the project
     */
    #[Assert\Choice(['draft', 'planning', 'in_production', 'finished', 'archived'])]
    #[Groups(['extended', 'full', 'project:read', 'project:write', 'import'])]
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    protected ?string $status = null;


    /**
     * @var Part|null The (optional) part that represents the builds of this project in the stock
     */
    #[ORM\OneToOne(mappedBy: 'built_project', targetEntity: Part::class, cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['project:read', 'project:write'])]
    protected ?Part $build_part = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $order_only_missing_parts = false;

    #[Groups(['simple', 'extended', 'full', 'project:read', 'project:write'])]
    #[ORM\Column(type: Types::TEXT)]
    protected string $description = '';

    /**
     * @var Collection<int, ProjectAttachment>
     */
    #[ORM\OneToMany(mappedBy: 'element', targetEntity: ProjectAttachment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['name' => Criteria::ASC])]
    #[Groups(['project:read', 'project:write'])]
    protected Collection $attachments;

    #[ORM\ManyToOne(targetEntity: ProjectAttachment::class)]
    #[ORM\JoinColumn(name: 'id_preview_attachment', onDelete: 'SET NULL')]
    #[Groups(['project:read', 'project:write'])]
    protected ?Attachment $master_picture_attachment = null;

    /** @var Collection<int, ProjectParameter>
     */
    #[ORM\OneToMany(mappedBy: 'element', targetEntity: ProjectParameter::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['group' => Criteria::ASC, 'name' => 'ASC'])]
    #[Groups(['project:read', 'project:write'])]
    protected Collection $parameters;

    #[Groups(['project:read'])]
    protected ?\DateTimeImmutable $addedDate = null;
    #[Groups(['project:read'])]
    protected ?\DateTimeImmutable $lastModified = null;


    /********************************************************************************
     *
     *   Getters
     *
     *********************************************************************************/

    public function __construct()
    {
        $this->attachments = new ArrayCollection();
        $this->parameters = new ArrayCollection();
        parent::__construct();
        $this->bom_entries = new ArrayCollection();
        $this->children = new ArrayCollection();
    }

    public function __clone()
    {
        //When cloning this project, we have to clone each bom entry too.
        if ($this->id) {
            $bom_entries = $this->bom_entries;
            $this->bom_entries = new ArrayCollection();
            //Set master attachment is needed
            foreach ($bom_entries as $bom_entry) {
                $clone = clone $bom_entry;
                $this->addBomEntry($clone);
            }
        }

        //Parent has to be last call, as it resets the ID
        parent::__clone();
    }

    /**
     *  Get the order quantity of this device.
     *
     * @return int the order quantity
     */
    public function getOrderQuantity(): int
    {
        return $this->order_quantity;
    }

    /**
     *  Get the "order_only_missing_parts" attribute.
     *
     * @return bool the "order_only_missing_parts" attribute
     */
    public function getOrderOnlyMissingParts(): bool
    {
        return $this->order_only_missing_parts;
    }

    /********************************************************************************
     *
     *   Setters
     *
     *********************************************************************************/

    /**
     *  Set the order quantity.
     *
     * @param int $new_order_quantity the new order quantity
     *
     * @return $this
     */
    public function setOrderQuantity(int $new_order_quantity): self
    {
        if ($new_order_quantity < 0) {
            throw new InvalidArgumentException('The new order quantity must not be negative!');
        }
        $this->order_quantity = $new_order_quantity;

        return $this;
    }

    /**
     *  Set the "order_only_missing_parts" attribute.
     *
     * @param bool $new_order_only_missing_parts the new "order_only_missing_parts" attribute
     */
    public function setOrderOnlyMissingParts(bool $new_order_only_missing_parts): self
    {
        $this->order_only_missing_parts = $new_order_only_missing_parts;

        return $this;
    }

    public function getBomEntries(): Collection
    {
        return $this->bom_entries;
    }

    /**
     * @return $this
     */
    public function addBomEntry(ProjectBOMEntry $entry): self
    {
        $entry->setProject($this);
        $this->bom_entries->add($entry);
        return $this;
    }

    /**
     * @return $this
     */
    public function removeBomEntry(ProjectBOMEntry $entry): self
    {
        $this->bom_entries->removeElement($entry);
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): Project
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return string
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * @param  string  $status
     */
    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    /**
     * Checks if this project has an associated part representing the builds of this project in the stock.
     */
    public function hasBuildPart(): bool
    {
        return $this->build_part instanceof Part;
    }

    /**
     * Gets the part representing the builds of this project in the stock, if it is existing
     */
    public function getBuildPart(): ?Part
    {
        return $this->build_part;
    }

    /**
     * Sets the part representing the builds of this project in the stock.
     */
    public function setBuildPart(?Part $build_part): void
    {
        $this->build_part = $build_part;
        if ($build_part instanceof Part) {
            $build_part->setBuiltProject($this);
        }
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context, $payload): void
    {
        //If this project has subprojects, and these have builds part, they must be included in the BOM
        foreach ($this->getChildren() as $child) {
            if (!$child->getBuildPart() instanceof Part) {
                continue;
            }
            //We have to search all bom entries for the build part
            $found = false;
            foreach ($this->getBomEntries() as $bom_entry) {
                if ($bom_entry->getPart() === $child->getBuildPart()) {
                    $found = true;
                    break;
                }
            }

            //When the build part is not found, we have to add an error
            if (!$found) {
                $context->buildViolation('project.bom_has_to_include_all_subelement_parts')
                    ->atPath('bom_entries')
                    ->setParameter('%project_name%', $child->getName())
                    ->setParameter('%part_name%', $child->getBuildPart()->getName())
                    ->addViolation();
            }
        }
    }

    //gets the total value of the parts contained in this project
    public function getTotalCost(): string
    {
        //TODO implement currency correctly
        //TODO also include subprojects in the calculation
        //TODO prevent crash if one or more parts don´t have price information

        $bom_entries = $this->bom_entries;
        $totalCost = BigDecimal::of(0);
        $entriesWithoutPrice = 0;

        foreach ($bom_entries as $bom_entry) {
            $quantity = BigDecimal::of($bom_entry->getQuantity());
            $orderdetails = $bom_entry->getPart()->getOrderdetails()[0] ?? null;

            // Check if orderdetails is null first, before accessing getPricedetails()
            if ($orderdetails === null || ($pricedetails = $orderdetails->getPricedetails()[0] ?? null) === null) {
                $entriesWithoutPrice++;
                continue;
            }

            $pricePerUnit = $pricedetails->getPricePerUnit();
            $totalCost = $totalCost->plus($quantity->multipliedBy($pricePerUnit));
        }

        $roundedValue = number_format(round($totalCost->toFloat(), 2), 2);

        $this->setEntriesWithoutPriceInformation($entriesWithoutPrice);
        return (string) ("€" . $roundedValue);
    }

    private int $entriesWithoutPriceInformation = 0;

    public function getEntriesWithPriceInformation(): int
    {
        return $this->entriesWithoutPriceInformation;
    }

    public function setEntriesWithoutPriceInformation(int $entriesWithoutPriceInformation): void
    {
        $this->entriesWithoutPriceInformation = $entriesWithoutPriceInformation;
    }
}
