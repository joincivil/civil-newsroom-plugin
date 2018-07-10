const { PluginSidebar, PluginSidebarMoreMenuItem } = window.wp.editPost;
const { registerPlugin } = window.wp.plugins;
const { withDispatch } = window.wp.data;
const { compose, PanelBody } = window.wp.element;
import * as React from "react";
import * as ReactDom from "react-dom";
import { getCivil } from "../util";
import { Civil } from "@joincivil/core";
import "./store";
import { ThemeProvider } from "styled-components";
import BlockchainSignPanel from "./sign";
import BlockchainPublishPanel from "./publish";

export interface BlockchainPluginProps {
  onNetworkChange(networkName: string): void;
}

class BlockchainPluginInnerComponent extends React.Component<BlockchainPluginProps> {
  public civil: Civil | undefined;
  public networkStream: any;

  constructor(props: BlockchainPluginProps) {
    super(props);
    this.civil = getCivil();
  }
  public componentDidMount(): void {
    if (this.civil) {
      this.networkStream = this.civil.networkNameStream.subscribe(this.props.onNetworkChange);
    }
  }
  public componentWillUnmount(): void {
    if (this.networkStream) {
      this.networkStream.unsubscribe();
    }
  }
  public render(): JSX.Element {
    const content = this.civil ? (
      this.props.children
    ) : (
      <h3>
        You need an in-browser Ethereum wallet. We recommend <a href="https://metamask.io/">MetaMask</a>.
      </h3>
    );
    return <>{content}</>;
  }
}

const BlockchainPluginInner = compose([
  withDispatch(
    (dispatch: any): BlockchainPluginProps => {
      const { setIsCorrectNetwork } = dispatch("civil/blockchain");
      const onNetworkChange = (networkName: string) => dispatch(setIsCorrectNetwork(networkName));
      return {
        onNetworkChange,
      };
    },
  ),
])(BlockchainPluginInnerComponent);

class CivilSidebarToggleComponent extends React.Component {
  public divRef: HTMLDivElement | null;
  public el: HTMLDivElement;

  constructor(props: any) {
    super(props);
    this.divRef = null;
    this.el = document.createElement("div");
  }

  public componentDidMount(): void {
    if (this.divRef) {
      const buttonContainer = this.divRef.parentElement;
      buttonContainer!.style.height = "0px";
      buttonContainer!.style.width = "0px";
      buttonContainer!.style.padding = "0";
      buttonContainer!.parentNode!.insertBefore(this.el, buttonContainer!.nextSibling)
    }
  }

  public render(): JSX.Element {
    const portal = ReactDom.createPortal(<h4>Civil</h4>, this.el);
    return <>
      {portal}
      <div ref={el => this.divRef = el}></div>
    </>;
  }
};

const CivilSidebarToggle = (
  <><CivilSidebarToggleComponent/></>
);

const CivilSidebar = () => {
  let panelContent = (
    <h3>
      Please take a moment to set up your<a href="/wp-admin/admin.php?page=civil-newsroom-protocol-management">
        Civil Newsroom contract
      </a>
    </h3>
  );
  if (window.civilNamespace.newsroomAddress) {
    panelContent = (
      <BlockchainPluginInner>
        <BlockchainSignPanel />
        <BlockchainPublishPanel />
      </BlockchainPluginInner>
    );
  }
  return (
    <>
      <PluginSidebar name="civil-sidebar" title="Civil">
        <ThemeProvider theme={
          {
            primaryButtonBackground: "#0085ba",
            primaryButtonColor: "#fff",
            primaryButtonHoverBackground: "#008ec2",
            primaryButtonDisabledBackground: "#008ec2",
            primaryButtonDisabledColor: "#66c6e4",
            primaryButtonTextTransform: "none",
            secondaryButtonColor: "#555555",
            secondaryButtonBackground: "transparent",
            secondaryButtonBorder: "#cccccc",
            borderlessButtonColor: "#0085ba",
            borderlessButtonHoverColor: "#008ec2",
          }
        }>
          {panelContent}
        </ThemeProvider>
      </PluginSidebar>
      <PluginSidebarMoreMenuItem target="civil-sidebar">Civil</PluginSidebarMoreMenuItem>
    </>
  );
};

registerPlugin("civil-sidebar", {
  icon: CivilSidebarToggle,
  render: CivilSidebar,
});
