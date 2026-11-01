<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'app_company_profile')]
class CompanyProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;

    #[ORM\Column(name: 'legal_name', length: 180)]
    private string $legalName = '';

    #[ORM\Column(length: 255)]
    private string $address = '';

    #[ORM\Column(name: 'postal_code', length: 20)]
    private string $postalCode = '';

    #[ORM\Column(length: 120)]
    private string $city = '';

    #[ORM\Column(length: 80)]
    private string $country = 'France';

    #[ORM\Column(length: 9, nullable: true)]
    private ?string $siren = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(name: 'vat_number', length: 20, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(name: 'legal_form', length: 120, nullable: true)]
    private ?string $legalForm = null;

    #[ORM\Column(name: 'share_capital', length: 80, nullable: true)]
    private ?string $shareCapital = null;

    #[ORM\Column(name: 'rcs_city', length: 120, nullable: true)]
    private ?string $rcsCity = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'payment_terms', length: 255, nullable: true)]
    private ?string $paymentTerms = null;

    #[ORM\Column(name: 'late_penalty_rate', length: 40, nullable: true)]
    private ?string $latePenaltyRate = null;

    #[ORM\Column(name: 'recovery_fee', length: 20, nullable: true)]
    private ?string $recoveryFee = null;

    #[ORM\Column(name: 'vat_regime_note', length: 255, nullable: true)]
    private ?string $vatRegimeNote = null;

    #[ORM\Column(name: 'payment_delay_days', type: 'integer', options: ['default' => 30])]
    private int $paymentDelayDays = 30;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct() { $this->updatedAt = new DateTimeImmutable(); }

    public function getId(): int { return $this->id; }
    public function getLegalName(): string { return $this->legalName; }
    public function getAddress(): string { return $this->address; }
    public function getPostalCode(): string { return $this->postalCode; }
    public function getCity(): string { return $this->city; }
    public function getCountry(): string { return $this->country; }
    public function getSiren(): ?string { return $this->siren; }
    public function getSiret(): ?string { return $this->siret; }
    public function getVatNumber(): ?string { return $this->vatNumber; }
    public function getLegalForm(): ?string { return $this->legalForm; }
    public function getShareCapital(): ?string { return $this->shareCapital; }
    public function getRcsCity(): ?string { return $this->rcsCity; }
    public function getEmail(): ?string { return $this->email; }
    public function getPhone(): ?string { return $this->phone; }
    public function getPaymentTerms(): ?string { return $this->paymentTerms; }
    public function getLatePenaltyRate(): ?string { return $this->latePenaltyRate; }
    public function getRecoveryFee(): ?string { return $this->recoveryFee; }
    public function getVatRegimeNote(): ?string { return $this->vatRegimeNote; }
    public function getPaymentDelayDays(): int { return $this->paymentDelayDays; }

    public function update(array $data): self
    {
        foreach (['legalName','address','postalCode','city','country'] as $key) {
            if (trim((string) ($data[$key] ?? '')) === '') {
                throw new InvalidArgumentException('Les champs raison sociale, adresse, code postal, ville et pays sont obligatoires.');
            }
        }
        $siren = preg_replace('/\D+/', '', (string) ($data['siren'] ?? ''));
        $siret = preg_replace('/\D+/', '', (string) ($data['siret'] ?? ''));
        if ($siren !== '' && strlen($siren) !== 9) { throw new InvalidArgumentException('Le SIREN doit contenir 9 chiffres.'); }
        if ($siret !== '' && strlen($siret) !== 14) { throw new InvalidArgumentException('Le SIRET doit contenir 14 chiffres.'); }
        if ($siren !== '' && $siret !== '' && !str_starts_with($siret, $siren)) { throw new InvalidArgumentException('Le SIRET doit commencer par le SIREN saisi.'); }
        $this->legalName = trim((string) $data['legalName']);
        $this->address = trim((string) $data['address']);
        $this->postalCode = trim((string) $data['postalCode']);
        $this->city = trim((string) $data['city']);
        $this->country = trim((string) $data['country']);
        $this->siren = $siren !== '' ? $siren : null;
        $this->siret = $siret !== '' ? $siret : null;
        $this->vatNumber = trim((string) ($data['vatNumber'] ?? '')) ?: null;
        $this->legalForm = trim((string) ($data['legalForm'] ?? '')) ?: null;
        $this->shareCapital = trim((string) ($data['shareCapital'] ?? '')) ?: null;
        $this->rcsCity = trim((string) ($data['rcsCity'] ?? '')) ?: null;
        $this->email = trim((string) ($data['email'] ?? '')) ?: null;
        $this->phone = trim((string) ($data['phone'] ?? '')) ?: null;
        $this->paymentTerms = trim((string) ($data['paymentTerms'] ?? '')) ?: null;
        $this->latePenaltyRate = trim((string) ($data['latePenaltyRate'] ?? '')) ?: null;
        $this->recoveryFee = trim((string) ($data['recoveryFee'] ?? '')) ?: null;
        if ($this->recoveryFee !== null && (!is_numeric($this->recoveryFee) || (float) $this->recoveryFee < 0)) { throw new InvalidArgumentException('L’indemnité de recouvrement doit être un montant numérique positif ou nul.'); }
        $this->vatRegimeNote = trim((string) ($data['vatRegimeNote'] ?? '')) ?: null;
        $delay = filter_var($data['paymentDelayDays'] ?? 30, FILTER_VALIDATE_INT);
        if ($delay === false || $delay < 0 || $delay > 365) { throw new InvalidArgumentException('Le délai de paiement doit être compris entre 0 et 365 jours.'); }
        $this->paymentDelayDays = $delay;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getInvoiceReadinessErrors(): array
    {
        $errors = [];
        if ($this->legalName === '' || $this->address === '' || $this->postalCode === '' || $this->city === '' || $this->country === '') { $errors[] = 'Identité et adresse du vendeur incomplètes.'; }
        if ($this->siren === null || $this->siret === null) { $errors[] = 'SIREN et SIRET du vendeur requis.'; }
        if ($this->paymentTerms === null || $this->latePenaltyRate === null || $this->recoveryFee === null || $this->vatRegimeNote === null) { $errors[] = 'Conditions de paiement, taux des pénalités de retard et indemnité de recouvrement à configurer.'; }
        return $errors;
    }

    public function toInvoiceSnapshot(): array
    {
        return ['legal_name'=>$this->legalName,'address'=>$this->address,'postal_code'=>$this->postalCode,'city'=>$this->city,'country'=>$this->country,'siren'=>$this->siren,'siret'=>$this->siret,'vat_number'=>$this->vatNumber,'legal_form'=>$this->legalForm,'share_capital'=>$this->shareCapital,'rcs_city'=>$this->rcsCity,'email'=>$this->email,'phone'=>$this->phone,'payment_terms'=>$this->paymentTerms,'late_penalty_rate'=>$this->latePenaltyRate,'recovery_fee'=>$this->recoveryFee,'vat_regime_note'=>$this->vatRegimeNote,'payment_delay_days'=>$this->paymentDelayDays];
    }
}
