<?php

namespace App\Model\Invoice;


class Invoice
{


    private string $number;
    private \DateTime $issueDate;
    private \DateTime $dueDate;

    private array $lines = [];


    /**
     * Get the value of dueDate
     *
     * @return \DateTime
     */
    public function getDueDate(): \DateTime
    {
        return $this->dueDate;
    }

    /**
     * Set the value of dueDate
     *
     * @param \DateTime $dueDate
     *
     * @return self
     */
    public function setDueDate(\DateTime $dueDate): self
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    /**
     * Get the value of issueDate
     *
     * @return \DateTime
     */
    public function getIssueDate(): \DateTime
    {
        return $this->issueDate;
    }

    /**
     * Set the value of issueDate
     *
     * @param \DateTime $issueDate
     *
     * @return self
     */
    public function setIssueDate(\DateTime $issueDate): self
    {
        $this->issueDate = $issueDate;

        return $this;
    }

    /**
     * Get the value of number
     *
     * @return string
     */
    public function getNumber(): string
    {
        return $this->number;
    }

    /**
     * Set the value of number
     *
     * @param string $number
     *
     * @return self
     */
    public function setNumber(string $number): self
    {
        $this->number = $number;

        return $this;
    }

    /**
     * Get the value of lines
     *
     * @return InvoiceLine[]
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Set the value of lines
     *
     * @param array $lines
     *
     * @return self
     */
    public function setLines(array $lines): self
    {
        $this->lines = $lines;

        return $this;
    }
}
