<?php

namespace App\Model\Invoice;

class InvoiceAddress
{

    private string $vat;
    private string $name;
    private string $address;
    private string $city;
    private ?string $state;
    private string $country;





    /**
     * Get the value of name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the value of name
     *
     * @param string $name
     *
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get the value of address
     *
     * @return string
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * Set the value of address
     *
     * @param string $address
     *
     * @return self
     */
    public function setAddress(string $address): self
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Get the value of city
     *
     * @return string
     */
    public function getCity(): string
    {
        return $this->city;
    }

    /**
     * Set the value of city
     *
     * @param string $city
     *
     * @return self
     */
    public function setCity(string $city): self
    {
        $this->city = $city;

        return $this;
    }

    /**
     * Get the value of state
     *
     * @return ?string
     */
    public function getState(): ?string
    {
        return $this->state;
    }

    /**
     * Set the value of state
     *
     * @param ?string $state
     *
     * @return self
     */
    public function setState(?string $state): self
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Get the value of country
     *
     * @return string
     */
    public function getCountry(): string
    {
        return $this->country;
    }

    /**
     * Set the value of country
     *
     * @param string $country
     *
     * @return self
     */
    public function setCountry(string $country): self
    {
        $this->country = $country;

        return $this;
    }

    /**
     * Get the value of vat
     *
     * @return string
     */
    public function getVat(): string
    {
        return $this->vat;
    }

    /**
     * Set the value of vat
     *
     * @param string $vat
     *
     * @return self
     */
    public function setVat(string $vat): self
    {
        $this->vat = $vat;

        return $this;
    }
}
