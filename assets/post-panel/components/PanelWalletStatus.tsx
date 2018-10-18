import * as React from "react";
import styled from "styled-components";
const { compose } = window.wp.compose;
const { withSelect } = window.wp.data;
import { SelectType } from "../../../typings/gutenberg";
import { EthAddress } from "@joincivil/core";
import { fonts, Button, buttonSizes, AddressWithMetaMaskIcon } from "@joincivil/components";
import { hasInjectedProvider } from "../../util";
import { saveAddressToProfile } from "../../api-helpers";
import { ErrorText, ErrorHeading, BodySection } from "../styles";
import { NETWORK_NICE_NAME } from "../../constants";

export interface PanelWalletStatusProps {
  noProvider: boolean;
  isCorrectNetwork: boolean;
  wpUserWalletAddress?: EthAddress;
  web3ProviderAddress?: EthAddress;
}

export interface PanelWalletStatusState {
  creationModalOpen: boolean;
  profileWalletAddress?: EthAddress;
}

const ProfileWalletAddress = styled.div`
  word-wrap: break-word;
  font-family: ${fonts.MONOSPACE};
  margin-top: -4px;
  margin-bottom: 8px;
`;
const WalletAddressLabel = styled.div`
  font-weight: 500;
  margin-bottom: 8px;
`;

class PanelWalletStatusComponent extends React.Component<PanelWalletStatusProps, PanelWalletStatusState> {
  public render(): JSX.Element | null {
    const faqText = (
      <>
        <a href="https://cvlconsensys.zendesk.com/hc/en-us/categories/360001000232-Journalists" target="_blank">
          Read our FAQ
        </a>{" "}
        for more help.
      </>
    );
    let errorHeading = null;
    let errorBody = null;
    if (this.props.noProvider) {
      errorHeading = "Not logged into wallet";
      errorBody = (
        <p>
          Don’t have a wallet? Having a wallet is mandatory and we recommend installing{" "}
          <a href="https://metamask.io/" target="_blank">
            MetaMask
          </a>, where you can create and set up your wallet and address. {faqText}
        </p>
      );
    } else if (!this.props.web3ProviderAddress) {
      errorHeading = "Wallet locked";
      errorBody = <p>Please log in to your wallet to continue. {faqText}</p>;
    } else if (!this.props.isCorrectNetwork) {
      errorHeading = "Change your network";
      errorBody = (
        <p>
          Looks like you’re using an unsupported Ethereum network. Make sure you're using the {NETWORK_NICE_NAME}.{" "}
          {faqText}
        </p>
      );
    } else if (!this.props.wpUserWalletAddress) {
      errorHeading = "Not saved to profile";
      errorBody = (
        <>
          <p>You must save your wallet address to your WordPress user profile before continuing.</p>
          <WalletAddressLabel>Public wallet address</WalletAddressLabel>
          <AddressWithMetaMaskIcon address={this.props.web3ProviderAddress} />
          <Button size={buttonSizes.MEDIUM_WIDE} onClick={this.saveAddress}>
            Save to Your Profile
          </Button>
        </>
      );
    } else if (this.props.wpUserWalletAddress !== this.props.web3ProviderAddress) {
      errorHeading = "Wallet address mismatch";
      errorBody = (
        <>
          <p>Your WordPress user profile wallet address does not match your MetaMask wallet address.</p>
          <WalletAddressLabel>Profile wallet address</WalletAddressLabel>
          <ProfileWalletAddress>{this.props.wpUserWalletAddress}</ProfileWalletAddress>
          <WalletAddressLabel>Connected wallet address</WalletAddressLabel>
          <AddressWithMetaMaskIcon address={this.props.web3ProviderAddress} />
          <Button size={buttonSizes.MEDIUM_WIDE} onClick={this.saveAddress}>
            Update Profile Address
          </Button>
        </>
      );
    } else {
      return null;
    }

    return (
      <BodySection>
        <ErrorHeading>
          Wallet
          {errorHeading && <ErrorText>{errorHeading}</ErrorText>}
        </ErrorHeading>
        {errorBody}
      </BodySection>
    );
  }

  private saveAddress = async () => saveAddressToProfile(this.props.web3ProviderAddress!);
}

export const PanelWalletStatus = compose([
  withSelect(
    (select: SelectType, ownProps: Partial<PanelWalletStatusProps>): Partial<PanelWalletStatusProps> => {
      const { isCorrectNetwork, getWeb3ProviderAddress, getCurrentWpUserAddress } = select("civil/blockchain");
      return {
        noProvider: !hasInjectedProvider(),
        isCorrectNetwork: isCorrectNetwork(),
        web3ProviderAddress: getWeb3ProviderAddress(),
        wpUserWalletAddress: getCurrentWpUserAddress(),
      };
    },
  ),
])(PanelWalletStatusComponent);
