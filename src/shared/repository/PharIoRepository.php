<?php
namespace PharIo\Phive;

use PharIo\Version\Version;

class PharIoRepository implements SourceRepository {

    /**
     * @var XmlFile
     */
    private $xmlFile;

    /**
     * PharIoRepository constructor.
     *
     * @param XmlFile $xmlFile
     */
    public function __construct(XmlFile $xmlFile) {
        $this->xmlFile = $xmlFile;
    }

    /**
     * @param RequestedPhar $requestedPhar
     *
     * @return ReleaseCollection
     */
    public function getReleasesByRequestedPhar(RequestedPhar $requestedPhar) {
        $releases = new ReleaseCollection();
        $query = sprintf('//phive:phar[@name="%s"]/phive:release', $requestedPhar->getAlias()->asString());
        foreach ($this->xmlFile->query($query) as $releaseNode) {
            /** @var \DOMElement $releaseNode */
            $releases->add(
                new SupportedRelease(
                    $requestedPhar->getAlias()->asString(),
                    new Version($releaseNode->getAttribute('version')),
                    new PharUrl($releaseNode->getAttribute('url')),
                    $this->getSignatureUrl($releaseNode),
                    $this->getHash($releaseNode)
                )
            );
        }

        return $releases;
    }

    /**
     * @param \DOMElement $releaseNode
     *
     * @return Hash
     * @throws InvalidHashException
     */
    private function getHash(\DOMElement $releaseNode) {
        /** @var \DOMElement $hashNode */
        $hashNode = $releaseNode->getElementsByTagName('hash')->item(0);
        $type = $hashNode->getAttribute('type');
        $hashValue = $hashNode->getAttribute('value');
        switch ($type) {
            case 'sha-1':
                return new Sha1Hash($hashValue);
            case 'sha-256':
                return new Sha256Hash($hashValue);
            case 'sha-384':
                return new Sha384Hash($hashValue);
            case 'sha-512':
                return new Sha512Hash($hashValue);
        }
        throw new InvalidHashException(sprintf('Unsupported Hash Type %s', $type));
    }

    private function getSignatureUrl(\DOMElement $releaseNode) {
        /** @var \DOMElement $signatureNode */
        $signatureNode = $releaseNode->getElementsByTagName('signature')->item(0);
        if ($signatureNode->hasAttribute('url')) {
            return new Url($signatureNode->getAttribute('url'));
        }

        return new Url($releaseNode->getAttribute('url') . '.asc');
    }

}
